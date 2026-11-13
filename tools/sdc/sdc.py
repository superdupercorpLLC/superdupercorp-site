#!/usr/bin/env python3
"""
sdc: send your work to the SUPER DUPER CORP site.

Everything arrives on the site as a hidden DRAFT. Nothing is ever published by this tool.

  sdc setup                      connect to your site (asks for the site address + uploader key)
  sdc scan  PATH [-o plan.csv]   look through a drive/folder and write a plan you can review in Numbers/Excel
  sdc upload plan.csv            upload everything marked include=yes in a plan
  sdc sync  PATH                 scan + upload in one go (every sub-folder becomes a project)
  sdc watch PATH [--every 15]    keep a folder synced automatically (runs in the background on your Mac)
  sdc unwatch                    stop the background sync
  sdc web URL [URL ...]          import a Behance project or any web page with your work on it
  sdc wordpress URL [--dry-run]  import portfolio items/posts from a WordPress site
  sdc instagram EXPORT_FOLDER    import posts from an Instagram "Download your information" export
  sdc drafts pull FOLDER         download drafts that need details (for Claude to fill in)
  sdc drafts push FOLDER         send the filled-in details back to the site (still drafts)
  sdc status                     what has been uploaded so far

Works with the Python 3 that ships with macOS. No extra installs needed.
"""
import argparse, csv, hashlib, html, json, mimetypes, os, re, shutil, sqlite3, subprocess
import sys, tempfile, time, uuid, urllib.error, urllib.parse, urllib.request
from html.parser import HTMLParser

VERSION = "1.0"
HOME = os.path.expanduser("~/.sdc")
CONFIG = os.path.join(HOME, "config.json")
DB = os.path.join(HOME, "state.db")
UA = "sdc-uploader/" + VERSION

IMAGE_OK = {".jpg", ".jpeg", ".png", ".gif", ".webp"}
IMAGE_CONVERT = {".heic", ".heif", ".tif", ".tiff", ".psd", ".bmp", ".pdf"}
VIDEO = {".mp4", ".m4v", ".mov", ".webm"}
SOURCE_FILES = {".ai", ".indd", ".idml", ".sketch", ".fig", ".xd", ".aep", ".prproj", ".fla", ".eps", ".afdesign",
                ".afphoto", ".blend", ".c4d", ".psb", ".cr2", ".cr3", ".nef", ".arw", ".dng", ".raf"}
SKIP_DIRS = {".trashes", ".spotlight-v100", ".fseventsd", ".documentrevisions-v100", ".temporaryitems", "__macosx",
             "system volume information", "$recycle.bin", "node_modules", ".git", "library", "applications",
             "links", "fonts", "document fonts", "raw", "wip", "working", "work in progress", "source", "src",
             "old", "_old", "backup", "backups", "trash", "rejects", "unused", "outtakes", "temp", "tmp", "cache"}
GENERIC_DIRS = {"final", "finals", "final files", "export", "exports", "exported", "jpg", "jpgs", "jpeg", "jpegs",
                "png", "pngs", "pdf", "pdfs", "web", "print", "hi-res", "hires", "high res", "low-res", "lowres",
                "low res", "selects", "images", "image", "img", "imgs", "assets", "output", "outputs",
                "deliverables", "delivery", "renders", "render", "previews", "preview", "photos", "pics",
                "approved", "presentation", "for web", "for print", "online", "social"}
GOOD_DIRS = {"final", "finals", "final files", "export", "exports", "selects", "approved", "deliverables", "delivery"}
CATEGORY_HINTS = [
    ("personal", r"personal|\bart\b|artwork|paint|sketch|drawing|exhibit|gallery show|zine|experiment|side project|studio work|canvas|print series"),
    ("corporate", r"corporate|annual report|internal|investor|\bhr\b|onboarding|town ?hall|all ?hands|employee|intranet|deck|keynote"),
    ("agency", r"agency|\bvia\b|freelance for|\bw\+k\b|wieden|ogilvy|droga|bbdo|ddb|tbwa|leo burnett|publicis|saatchi|grey|mccann|r/ga|huge|instrument"),
    ("commercial", r"campaign|brand|packag|product|launch|retail|ad\b|ads\b|advert|commercial|client"),
]
DISCIPLINE_HINTS = [
    ("Branding", r"brand|identity|logo|\bci\b"), ("Illustration", r"illustrat|drawing|sketch"),
    ("Motion", r"motion|animation|anim|video|film|\.mp4|\.mov"), ("Photography", r"photo|shoot|\bdslr\b"),
    ("Print", r"poster|print|flyer|zine|book|editorial"), ("Packaging", r"packag|label|box"),
    ("Painting", r"paint|canvas|acrylic|oil|gouache|watercolou?r"), ("Digital", r"web|site|\bui\b|\bux\b|app|digital|social"),
    ("Campaigns", r"campaign|ooh|billboard|\bads?\b"), ("Art Direction", r"art direction|\bad\b|creative direction"),
]
YEAR_RE = re.compile(r"(?<!\d)(19[89]\d|20[0-4]\d)(?!\d)")


# ---------------------------------------------------------------------------- helpers
def say(*a):
    print(*a, flush=True)

def die(msg, code=1):
    print("✖ " + msg, file=sys.stderr)
    sys.exit(code)

def load_config():
    if not os.path.exists(CONFIG):
        die("Not set up yet. Run:  sdc setup")
    with open(CONFIG) as f:
        return json.load(f)

def db():
    os.makedirs(HOME, exist_ok=True)
    c = sqlite3.connect(DB)
    c.execute("create table if not exists projects (source_key text primary key, work_id text, title text, at text)")
    c.execute("create table if not exists files (sha text primary key, work_id text, path text, result text, at text)")
    return c

def sha256_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()

def size_to_bytes(s):
    m = re.match(r"^\s*(\d+)\s*([KMG]?)", str(s or ""), re.I)
    if not m:
        return 0
    return int(m.group(1)) * {"": 1, "K": 1024, "M": 1024 ** 2, "G": 1024 ** 3}[m.group(2).upper()]

def fix_mojibake(s):
    try:
        return s.encode("latin-1").decode("utf-8")
    except (UnicodeEncodeError, UnicodeDecodeError):
        return s

def nice_title(name):
    name = re.sub(r"[_]+", " ", name)
    name = YEAR_RE.sub("", name)
    name = re.sub(r"\s{2,}", " ", name).strip(" -–—.,")
    if name.isupper() or name.islower():
        name = name.title()
    return name or "Untitled"


# ---------------------------------------------------------------------------- API client
class Site:
    def __init__(self, cfg):
        self.base = cfg["site"].rstrip("/") + "/api/"
        self.key = cfg["key"]
        self._limits = None

    def _req(self, method, action, data=None, headers=None, params=""):
        url = self.base + "?a=" + action + params
        h = {"Authorization": "Bearer " + self.key, "X-SDC-Key": self.key, "User-Agent": UA, "Accept": "application/json"}
        h.update(headers or {})
        req = urllib.request.Request(url, data=data, headers=h, method=method)
        for attempt in range(4):
            try:
                with urllib.request.urlopen(req, timeout=300) as r:
                    return json.loads(r.read().decode("utf-8"))
            except urllib.error.HTTPError as e:
                body = e.read().decode("utf-8", "replace")
                try:
                    msg = json.loads(body).get("error", body[:200])
                except ValueError:
                    msg = body[:200] or str(e)
                if e.code in (502, 503, 504) and attempt < 3:
                    time.sleep(2 ** attempt * 2)
                    continue
                raise RuntimeError("%s (HTTP %s)" % (msg, e.code))
            except urllib.error.URLError as e:
                if attempt < 3:
                    time.sleep(2 ** attempt * 2)
                    continue
                raise RuntimeError("Can't reach the site: %s" % e.reason)

    def get(self, action, params=""):
        return self._req("GET", action, params=params)

    def post_json(self, action, payload):
        return self._req("POST", action, json.dumps(payload).encode("utf-8"), {"Content-Type": "application/json"})

    def upload(self, work_id, path, caption="", make_cover=False, filename=None):
        boundary = "----sdc" + uuid.uuid4().hex
        name = filename or os.path.basename(path)
        ctype = mimetypes.guess_type(name)[0] or "application/octet-stream"
        parts = []
        for k, v in (("id", work_id), ("caption", caption), ("make_cover", "1" if make_cover else "")):
            parts.append(("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n" % (boundary, k, v)).encode("utf-8"))
        with open(path, "rb") as f:
            blob = f.read()
        safe = name.replace('"', "")
        parts.append(("--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n"
                      % (boundary, safe, ctype)).encode("utf-8") + blob + b"\r\n")
        parts.append(("--%s--\r\n" % boundary).encode("utf-8"))
        return self._req("POST", "upload", b"".join(parts), {"Content-Type": "multipart/form-data; boundary=" + boundary})

    def max_upload(self):
        if self._limits is None:
            self._limits = self.get("ping").get("limits", {})
        a = size_to_bytes(self._limits.get("upload_max_filesize")) or 2 * 1024 ** 2
        b = size_to_bytes(self._limits.get("post_max_size")) or a
        return min(a, b) - 64 * 1024


# ---------------------------------------------------------------------------- media prep
def have_sips():
    return sys.platform == "darwin" and shutil.which("sips")

def convert_image(src, dst_dir, max_px=2800):
    """Return a path to a web-friendly copy (JPEG/PNG, ≤ max_px). Uses macOS `sips`, falls back to Pillow."""
    ext = os.path.splitext(src)[1].lower()
    keep_png = ext == ".png"
    out = os.path.join(dst_dir, uuid.uuid4().hex + (".png" if keep_png else ".jpg"))
    if have_sips():
        cmd = ["sips", "-Z", str(max_px), "-s", "format", "png" if keep_png else "jpeg"]
        if not keep_png:
            cmd += ["-s", "formatOptions", "85"]
        r = subprocess.run(cmd + [src, "--out", out], capture_output=True)
        if r.returncode == 0 and os.path.exists(out) and os.path.getsize(out) > 0:
            return out
    try:
        from PIL import Image  # optional
        im = Image.open(src)
        im.thumbnail((max_px, max_px))
        if keep_png:
            im.save(out, "PNG", optimize=True)
        else:
            im.convert("RGB").save(out, "JPEG", quality=85)
        return out
    except Exception:
        return None

def prepare(path, tmp, limit):
    """→ (path_to_upload, note) or (None, reason)."""
    ext = os.path.splitext(path)[1].lower()
    size = os.path.getsize(path)
    if ext in VIDEO:
        if ext == ".mov" and size > limit:
            return None, "video too big for your host (%.0f MB). Export a smaller MP4." % (size / 1e6)
        if size > limit:
            return None, "video too big for your host (%.0f MB, limit %.0f MB)" % (size / 1e6, limit / 1e6)
        return path, ""
    if ext == ".gif":
        return (path, "") if size <= limit else (None, "GIF too big")
    if ext in IMAGE_OK and size <= min(limit, 3 * 1024 ** 2):
        return path, ""
    if ext in IMAGE_OK or ext in IMAGE_CONVERT:
        out = convert_image(path, tmp)
        if out and os.path.getsize(out) <= limit:
            return out, "converted"
        return None, "could not convert %s on this computer" % ext
    return None, "not a supported file type"


# ---------------------------------------------------------------------------- scanning
def lname(p):
    return os.path.basename(p.rstrip("/")).strip().lower()

def project_dir_for(d, root):
    """Walk up past generic folder names (Finals, Exports, JPG…) to the folder that names the project."""
    cur = d
    while cur != root and (lname(cur) in GENERIC_DIRS or re.match(r"^(v|r|round ?|rev ?|version ?)\d+$", lname(cur))):
        cur = os.path.dirname(cur)
    return cur

def guess(path_text, files):
    t = path_text.lower()
    cat = "commercial"
    for c, rx in CATEGORY_HINTS:
        if re.search(rx, t):
            cat = c
            break
    disc = [d for d, rx in DISCIPLINE_HINTS if re.search(rx, t + " " + " ".join(os.path.basename(f) for f in files[:50]).lower())]
    return cat, disc[:4]

def parse_name(folder_name):
    """'2014 - Nike - Air Max Launch' → (client='Nike', title='Air Max Launch')."""
    n = YEAR_RE.sub("", folder_name).strip(" _-–—.")
    parts = [p.strip(" _") for p in re.split(r"\s+[-–—|]\s+|\s*_-_\s*|\s*__\s*", n) if p.strip(" _")]
    if len(parts) >= 2:
        return nice_title(parts[0]), nice_title(" - ".join(parts[1:]))
    return "", nice_title(n)

def file_year(paths):
    years = []
    for p in paths[:40]:
        try:
            st = os.stat(p)
            years.append(time.localtime(getattr(st, "st_birthtime", st.st_mtime)).tm_year)
        except OSError:
            pass
    return min(years) if years else ""

def score(path, project_dir):
    rel = os.path.relpath(os.path.dirname(path), project_dir).lower()
    s = 0.0
    if any(part in GOOD_DIRS for part in rel.split(os.sep)):
        s += 3
    ext = os.path.splitext(path)[1].lower()
    if ext in IMAGE_OK:
        s += 1
    if ext in VIDEO:
        s += 0.5
    if re.search(r"thumb|icon|favicon|@1x|_sm\b|small|tmp|copy\b|\bcopy \d", os.path.basename(path).lower()):
        s -= 2
    try:
        s += min(os.path.getsize(path) / 4e6, 1.5)
    except OSError:
        pass
    return s

def scan(root, max_per_project=20, min_bytes=25 * 1024):
    root = os.path.abspath(os.path.expanduser(root))
    if not os.path.isdir(root):
        die("Folder not found: " + root)
    projects = {}
    for d, dirs, files in os.walk(root):
        dirs[:] = [x for x in dirs if not x.startswith(".") and x.lower() not in SKIP_DIRS and not x.endswith((".app", ".photoslibrary", ".fcpbundle", ".lrdata"))]
        media, sources = [], 0
        for f in files:
            if f.startswith("."):
                continue
            ext = os.path.splitext(f)[1].lower()
            p = os.path.join(d, f)
            if ext in SOURCE_FILES:
                sources += 1
            elif ext in IMAGE_OK or ext in VIDEO or (ext in IMAGE_CONVERT and ext != ".pdf"):
                try:
                    if os.path.getsize(p) >= min_bytes:
                        media.append(p)
                except OSError:
                    pass
        if not media and not sources:
            continue
        pd = project_dir_for(d, root)
        pr = projects.setdefault(pd, {"dir": pd, "files": [], "sources": 0})
        pr["files"] += media
        pr["sources"] += sources

    plan = []
    for pd, pr in sorted(projects.items()):
        if not pr["files"]:
            continue
        rel = os.path.relpath(pd, root)
        name = os.path.basename(pd) if pd != root else os.path.basename(root)
        client, title = parse_name(name)
        years = [int(y) for y in YEAR_RE.findall(rel if rel != "." else name)]
        year = years[-1] if years else file_year(pr["files"])
        cat, disc = guess(rel + " " + name, pr["files"])
        # prefer flat exports over layered PSD/TIFF when both exist
        flat = [f for f in pr["files"] if os.path.splitext(f)[1].lower() in IMAGE_OK | VIDEO]
        pool = flat or pr["files"]
        picked = sorted(pool, key=lambda f: -score(f, pd))[:max_per_project]
        plan.append({
            "include": "yes", "folder": pd, "title": title, "client": client, "year": year, "category": cat,
            "disciplines": ", ".join(disc), "files_found": len(pr["files"]), "files_to_send": len(picked),
            "design_files_skipped": pr["sources"], "files": picked,
        })
    return plan

PLAN_COLS = ["include", "title", "client", "year", "category", "disciplines", "files_found", "files_to_send", "design_files_skipped", "folder"]

def write_plan(plan, out):
    with open(out, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=PLAN_COLS, extrasaction="ignore")
        w.writeheader()
        for p in plan:
            w.writerow(p)
    side = out + ".files.json"
    with open(side, "w", encoding="utf-8") as f:
        json.dump({p["folder"]: p["files"] for p in plan}, f)

def read_plan(path, max_per_project):
    side = path + ".files.json"
    files = json.load(open(side, encoding="utf-8")) if os.path.exists(side) else {}
    out = []
    with open(path, newline="", encoding="utf-8-sig") as f:
        for row in csv.DictReader(f):
            if (row.get("include") or "").strip().lower() not in ("yes", "y", "1", "true", "x"):
                continue
            folder = row["folder"]
            fl = files.get(folder)
            if fl is None:  # plan edited by hand / new rows
                sub = scan(folder, max_per_project)
                fl = sub[0]["files"] if sub else []
            row["files"] = fl
            out.append(row)
    return out


# ---------------------------------------------------------------------------- uploading
def send_project(site, conn, p, via="uploader", source=None, source_key=None, quiet=False):
    source = source or p.get("folder", "")
    source_key = source_key or ("fs:" + hashlib.sha1(os.path.realpath(source).encode("utf-8")).hexdigest())
    # Skip projects whose files have all been sent before (e.g. the same image saved in two folders).
    pending = []
    for path in p["files"]:
        try:
            sha = sha256_file(path)
        except OSError:
            continue
        row = conn.execute("select result from files where sha=?", (sha,)).fetchone()
        if not (row and row[0] in ("ok", "duplicate")):
            pending.append(path)
    known = conn.execute("select work_id from projects where source_key=?", (source_key,)).fetchone()
    if not pending:
        return (known[0] if known else None), 0, len(p["files"]), 0, []
    payload = {k: p.get(k, "") for k in ("title", "client", "year", "category", "summary", "body", "agency", "role")}
    payload["disciplines"] = p.get("disciplines", "")
    payload.update({"source": source, "source_key": source_key, "via": via})
    res = site.post_json("create", payload)
    wid = res["id"]
    conn.execute("insert or replace into projects values (?,?,?,?)", (source_key, wid, payload["title"], time.strftime("%F %T")))
    conn.commit()
    limit = site.max_upload()
    sent = skipped = failed = 0
    notes = []
    with tempfile.TemporaryDirectory(prefix="sdc-") as tmp:
        for i, path in enumerate(p["files"]):
            try:
                sha = sha256_file(path)
            except OSError as e:
                failed += 1
                notes.append("%s: %s" % (os.path.basename(path), e))
                continue
            row = conn.execute("select result from files where sha=?", (sha,)).fetchone()
            if row and row[0] in ("ok", "duplicate"):
                skipped += 1
                continue
            up, note = prepare(path, tmp, limit)
            if not up:
                failed += 1
                notes.append("%s: %s" % (os.path.basename(path), note))
                conn.execute("insert or replace into files values (?,?,?,?,?)", (sha, wid, path, "skipped: " + note, time.strftime("%F %T")))
                conn.commit()
                continue
            try:
                name = os.path.splitext(os.path.basename(path))[0] + os.path.splitext(up)[1]
                r = site.upload(wid, up, make_cover=(i == 0), filename=name)
                result = "duplicate" if r.get("duplicate") else "ok"
                sent += result == "ok"
                skipped += result == "duplicate"
            except RuntimeError as e:
                if "HTTP 409" in str(e):
                    notes.append("project is already live, so new files were not added. Add them in the admin.")
                    break
                result = "error: %s" % e
                failed += 1
                notes.append("%s: %s" % (os.path.basename(path), e))
            conn.execute("insert or replace into files values (?,?,?,?,?)", (sha, wid, path, result, time.strftime("%F %T")))
            conn.commit()
            if not quiet:
                print("  %s %s" % ("↑" if result == "ok" else "·", os.path.basename(path)), flush=True)
    return wid, sent, skipped, failed, notes

def run_upload(site, plan, quiet=False):
    conn = db()
    tot = [0, 0, 0]
    for n, p in enumerate(plan, 1):
        if not quiet:
            say("\n[%d/%d] %s  (%s, %s)" % (n, len(plan), p["title"], p.get("year") or "no year", p.get("category")))
        try:
            wid, s, k, f, notes = send_project(site, conn, p, quiet=quiet)
        except RuntimeError as e:
            say("  ✖ %s" % e)
            if "HTTP 401" in str(e) or "HTTP 403" in str(e):
                die("The uploader key was rejected. Make a new one in Admin → Site copy → Uploader keys, then run: sdc setup")
            continue
        tot[0] += s; tot[1] += k; tot[2] += f
        for note in notes[:5]:
            say("  ! " + note)
    say("\nDone: %d uploaded, %d already there, %d skipped. Everything is a draft. Review it in Admin → Work → Needs details." % tuple(tot))


# ---------------------------------------------------------------------------- web import
class PageParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.meta, self.imgs, self.title, self._t = {}, [], "", False

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "meta":
            k = (a.get("property") or a.get("name") or "").lower()
            if k and a.get("content"):
                self.meta.setdefault(k, a["content"])
        elif tag in ("img", "source"):
            for k in ("data-src", "src", "data-original"):
                if a.get(k):
                    self.imgs.append(a[k])
            for k in ("srcset", "data-srcset"):
                if a.get(k):
                    best = sorted(((int(re.sub(r"\D", "", w) or 0), u) for u, _, w in
                                   (c.strip().partition(" ") for c in a[k].split(",") if c.strip())), reverse=True)
                    if best:
                        self.imgs.append(best[0][1])
        elif tag == "a" and re.search(r"\.(jpe?g|png|webp|gif)(\?|$)", a.get("href", ""), re.I):
            self.imgs.append(a["href"])
        elif tag == "title":
            self._t = True

    def handle_data(self, data):
        if self._t:
            self.title += data

    def handle_endtag(self, tag):
        if tag == "title":
            self._t = False

def fetch(url, binary=False):
    url = urllib.parse.quote(url, safe=":/?#[]@!$&'()*+,;=%~")  # spaces etc. in file names
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (Macintosh) " + UA})
    with urllib.request.urlopen(req, timeout=60) as r:
        data = r.read()
        return data if binary else data.decode(r.headers.get_content_charset() or "utf-8", "replace")

def import_web(site, urls, category=None, year=None, max_images=30):
    conn = db()
    for url in urls:
        say("\n→ " + url)
        if "instagram.com" in url:
            say("  Instagram blocks page imports. Use your Instagram data export instead:  sdc instagram EXPORT_FOLDER")
            continue
        try:
            page = fetch(url)
        except Exception as e:
            say("  ✖ couldn't load the page: %s" % e)
            continue
        pp = PageParser()
        pp.feed(page)
        behance = "behance.net" in url
        title = html.unescape(pp.meta.get("og:title") or pp.title or url).strip()
        title = re.sub(r"\s*(on Behance|\| Behance|[-|–]\s*Behance)\s*$", "", title)
        desc = html.unescape(pp.meta.get("og:description") or pp.meta.get("description") or "").strip()
        ym = re.search(r'"(?:datePublished|uploadDate|publishedOn)"\s*:\s*"?(\d{4})', page) or YEAR_RE.search(pp.meta.get("article:published_time", ""))
        yr = year or (int(ym.group(1)) if ym else "")
        seen, imgs = set(), []
        cands = ([pp.meta["og:image"]] if pp.meta.get("og:image") else []) + pp.imgs
        for u in cands:
            u = urllib.parse.urljoin(url, html.unescape(u.strip()))
            if not u.startswith("http") or re.search(r"\.svg(\?|$)|sprite|logo|avatar|icon|pixel|spacer|badge", u, re.I):
                continue
            if behance:
                if "project_modules" not in u:
                    continue
                u = re.sub(r"/project_modules/[^/]+/", "/project_modules/max_3840/", u)
            key = re.sub(r"\?.*$", "", u)
            if key not in seen:
                seen.add(key)
                imgs.append(u)
        imgs = imgs[:max_images]
        cat, disc = guess(title + " " + desc, [])
        say("  %s: %d images found" % (title, len(imgs)))
        with tempfile.TemporaryDirectory(prefix="sdc-web-") as tmp:
            files = []
            for i, u in enumerate(imgs):
                try:
                    try:
                        blob = fetch(u, binary=True)
                    except urllib.error.HTTPError:
                        if behance:
                            blob = fetch(u.replace("/max_3840/", "/1400/"), binary=True)
                        else:
                            raise
                except Exception:
                    continue
                if len(blob) < 15 * 1024:
                    continue
                ext = os.path.splitext(urllib.parse.urlparse(u).path)[1].lower() or ".jpg"
                if ext not in IMAGE_OK:
                    ext = ".jpg"
                p = os.path.join(tmp, "%02d%s" % (i, ext))
                with open(p, "wb") as f:
                    f.write(blob)
                files.append(p)
            if not files:
                say("  ✖ no usable images. The page may load its images with JavaScript.")
                continue
            proj = {"title": title[:160], "summary": desc[:240], "year": yr, "category": category or cat,
                    "disciplines": ", ".join(disc), "files": files}
            try:
                wid, s, k, f, notes = send_project(site, conn, proj, via="web", source=url, source_key="web:" + url)
                say("  ✓ draft created: %d images uploaded%s" % (s, (", %d already there" % k) if k else ""))
            except RuntimeError as e:
                say("  ✖ %s" % e)


# ---------------------------------------------------------------------------- instagram export
def import_instagram(site, folder, since=None, category="personal"):
    folder = os.path.abspath(os.path.expanduser(folder))
    jsons = []
    for d, _, files in os.walk(folder):
        jsons += [os.path.join(d, f) for f in files if re.match(r"^posts(_\d+)?\.json$", f)]
    if not jsons:
        die("No posts_*.json found. In Instagram: Settings → Accounts Center → Your information and permissions → "
            "Download your information → choose JSON format and include Posts. Then point sdc at the unzipped folder.")
    posts = []
    for j in jsons:
        data = json.load(open(j, encoding="utf-8"))
        if isinstance(data, dict):
            data = data.get("ig_posts") or data.get("posts") or []
        posts += data
    conn = db()
    say("Found %d posts." % len(posts))
    for post in posts:
        media = post.get("media") or []
        if not media:
            continue
        cap = fix_mojibake(post.get("title") or media[0].get("title") or "")
        ts = post.get("creation_timestamp") or media[0].get("creation_timestamp") or 0
        yr = time.localtime(ts).tm_year if ts else ""
        if since and yr and yr < since:
            continue
        files = []
        for m in media:
            uri = m.get("uri", "")
            p = os.path.join(folder, uri)
            if not os.path.exists(p):  # some exports nest one level deeper
                hits = [os.path.join(d, os.path.basename(uri)) for d, _, fs in os.walk(folder) if os.path.basename(uri) in fs]
                p = hits[0] if hits else ""
            if p and os.path.splitext(p)[1].lower() in IMAGE_OK | VIDEO | IMAGE_CONVERT:
                files.append(p)
        if not files:
            continue
        first = re.sub(r"[#@]\S+", "", cap.split("\n")[0]).strip()
        title = (first[:70].rsplit(" ", 1)[0] + "…") if len(first) > 70 else (first or "Instagram post %s" % time.strftime("%Y-%m-%d", time.localtime(ts)))
        cat, disc = guess(cap, files)
        proj = {"title": title, "summary": cap[:240], "body": cap, "year": yr, "category": category or cat,
                "disciplines": ", ".join(disc), "files": files}
        say("\n%s (%s)" % (title, yr))
        try:
            send_project(site, conn, proj, via="instagram", source="Instagram export: " + media[0].get("uri", ""),
                         source_key="ig:" + media[0].get("uri", str(ts)))
        except RuntimeError as e:
            say("  ✖ %s" % e)
    say("\nDone. Instagram posts are drafts. Delete the ones that aren't portfolio pieces.")


# ---------------------------------------------------------------------------- WordPress import
def html_to_text(h):
    h = re.sub(r"(?is)<(script|style).*?</\1>", "", h)
    h = re.sub(r"(?i)<br\s*/?>", "\n", h)
    h = re.sub(r"(?i)</(p|h[1-6]|li|div|blockquote)>", "\n\n", h)
    h = re.sub(r"(?i)<li[^>]*>", "• ", h)
    t = html.unescape(re.sub(r"<[^>]+>", "", h))
    t = re.sub(r"[ \t\xa0]+", " ", t)
    t = re.sub(r"\n\s*\n\s*(\n\s*)+", "\n\n", t)
    return "\n\n".join(p.strip() for p in t.split("\n\n") if p.strip())

def wp_original(u):
    """Strip WordPress size suffixes (photo-1024x683.jpg, -scaled) to get the full-size upload."""
    return re.sub(r"-(\d{2,5}x\d{2,5}|scaled)(?=\.[a-z]{3,4}$)", "", u.split("?")[0], flags=re.I)

def wp_items(base, kind=None):
    api = base.rstrip("/") + "/wp-json/wp/v2/"
    kinds = [kind] if kind else ["portfolio_item", "portfolio", "project", "projects", "posts"]
    for k in kinds:
        out, page = [], 1
        while True:
            try:
                data = json.loads(fetch(api + "%s?per_page=50&page=%d&_embed=1" % (k, page)))
            except Exception:
                break
            if not isinstance(data, list) or not data:
                break
            out += data
            page += 1
        if out:
            return k, out
    return None, []

def import_wordpress(site, base, kind=None, dry=False, max_images=24):
    k, items = wp_items(base, kind)
    if not items:
        die("No portfolio items or posts found at %s (is the WordPress REST API turned off?)" % base)
    say("Found %d %s items on %s%s" % (len(items), k, base, " (dry run: nothing is uploaded)" if dry else ""))
    conn = None if dry else db()
    for it in items:
        title = html.unescape(re.sub(r"<[^>]+>", "", it.get("title", {}).get("rendered", ""))).strip() or "Untitled"
        content = it.get("content", {}).get("rendered", "")
        body = html_to_text(content)
        excerpt = html_to_text(it.get("excerpt", {}).get("rendered", ""))
        summary = (excerpt or body).split("\n")[0]
        summary = summary if len(summary) <= 240 else summary[:237].rsplit(" ", 1)[0] + "…"
        year = int(it.get("date", "0")[:4]) if it.get("date") else ""
        imgs = []
        fm = (it.get("_embedded", {}).get("wp:featuredmedia") or [{}])[0].get("source_url")
        if fm:
            imgs.append(fm)
        for u in re.findall(r'https?://[^"\'\s<>()]+?/wp-content/uploads/[^"\'\s<>()]+?\.(?:jpe?g|png|webp|gif)', content, re.I):
            imgs.append(u)
        seen, uniq = set(), []
        for u in imgs:
            o = wp_original(u)
            if o not in seen:
                seen.add(o)
                uniq.append((o, u.split("?")[0]))
        uniq = uniq[:max_images]
        vid = re.search(r'(https?://(?:www\.)?(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/|(?:player\.)?vimeo\.com/(?:video/)?)[\w-]+)', content)
        cat, disc = guess(title + " " + body[:2000], [])
        say("\n%s (%s): %d images%s, %d words" % (title, year, len(uniq), ", video" if vid else "", len(body.split())))
        if dry:
            continue
        with tempfile.TemporaryDirectory(prefix="sdc-wp-") as tmp:
            files = []
            for i, (u, shown) in enumerate(uniq):
                try:
                    try:
                        blob = fetch(u, binary=True)
                    except urllib.error.HTTPError:
                        blob = fetch(shown, binary=True)  # full-size missing: use the size the page showed
                except Exception:
                    continue
                if len(blob) < 10 * 1024:
                    continue
                ext = os.path.splitext(urllib.parse.urlparse(u).path)[1].lower() or ".jpg"
                pth = os.path.join(tmp, "%02d-%s" % (i, os.path.basename(urllib.parse.urlparse(u).path)))
                with open(pth, "wb") as f:
                    f.write(blob)
                files.append(pth)
            proj = {"title": title[:160], "summary": summary, "body": body, "year": year, "category": cat,
                    "disciplines": ", ".join(disc), "files": files}
            try:
                wid, sent, k2, f2, notes = send_project(site, conn, proj, via="wordpress", source=it.get("link", base),
                                                        source_key="wp:" + it.get("link", str(it.get("id"))))
                if vid and wid:
                    site.post_json("update", {"id": wid, "video_url": vid.group(1)})
                say("  ✓ draft: %d images uploaded%s" % (sent, ("; " + "; ".join(notes[:2])) if notes else ""))
            except RuntimeError as e:
                say("  ✖ %s" % e)
    if not dry:
        say("\nDone. All imported as drafts. Review them in Admin → Work → Needs details.")


# ---------------------------------------------------------------------------- drafts for Claude
FILL_GUIDE = """# Fill in draft details

Each folder here is one DRAFT project on the SUPER DUPER CORP site. It holds `details.json` and the images.

For each folder:
1. Look at the images and read `details.json` (`source` says where it came from: folder path, web page or Instagram).
2. Fill in, honestly and plainly:
   - `title`: short and specific. No hype.
   - `client`: only if it is clear from the folder name, images or source. Otherwise leave it empty.
   - `year`: four digits, only if you're reasonably sure.
   - `category`: one of personal, commercial, corporate, agency.
   - `disciplines`: e.g. ["Branding", "Illustration"].
   - `summary`: one sentence, max 240 characters, describing what it is.
   - `body`: 1–3 short paragraphs. Describe only what you can see or what the source says.
     Never invent results, awards, metrics, quotes or client names.
3. Set `"ready": true` when done. If you can't tell what it is, set `"ready": false` and add a `"question"` for MaxP.

Then run:  sdc drafts push "{folder}"

Nothing gets published by this. MaxP reviews every draft in the admin and publishes it there.
"""

def drafts_pull(site, folder):
    folder = os.path.abspath(os.path.expanduser(folder))
    os.makedirs(folder, exist_ok=True)
    res = site.get("drafts")
    for d in res["drafts"]:
        sub = os.path.join(folder, re.sub(r"[^\w-]+", "-", d["title"]).strip("-")[:50] + "-" + d["id"])
        os.makedirs(sub, exist_ok=True)
        info = {k: d.get(k) for k in ("id", "title", "client", "agency", "year", "category", "role", "disciplines", "summary", "body", "source")}
        info["ready"] = False
        with open(os.path.join(sub, "details.json"), "w", encoding="utf-8") as f:
            json.dump(info, f, indent=2, ensure_ascii=False)
        for i, u in enumerate(d.get("images", [])[:8], 1):
            p = os.path.join(sub, "image-%02d%s" % (i, os.path.splitext(urllib.parse.urlparse(u).path)[1] or ".jpg"))
            if not os.path.exists(p):
                try:
                    with open(p, "wb") as f:
                        f.write(fetch(u, binary=True))
                except Exception:
                    pass
    with open(os.path.join(folder, "HOW-TO-FILL.md"), "w", encoding="utf-8") as f:
        f.write(FILL_GUIDE.replace("{folder}", folder))
    say("Pulled %d drafts into %s" % (res["count"], folder))
    say("Next: open Claude in that folder and say: \"Follow HOW-TO-FILL.md\". Then run: sdc drafts push \"%s\"" % folder)

def drafts_push(site, folder):
    folder = os.path.abspath(os.path.expanduser(folder))
    n = q = 0
    for d in sorted(os.listdir(folder)):
        p = os.path.join(folder, d, "details.json")
        if not os.path.exists(p):
            continue
        info = json.load(open(p, encoding="utf-8"))
        if not info.get("ready"):
            if info.get("question"):
                q += 1
                say("? %s: %s" % (info.get("title"), info["question"]))
            continue
        payload = {k: info[k] for k in ("id", "title", "client", "agency", "year", "category", "role", "disciplines", "summary", "body") if k in info}
        payload["ai"] = True
        try:
            site.post_json("update", payload)
            n += 1
            say("✓ " + info.get("title", ""))
            shutil.rmtree(os.path.join(folder, d), ignore_errors=True)
        except RuntimeError as e:
            say("✖ %s: %s" % (info.get("title"), e))
    say("\nSent %d drafts back (still hidden). %d have questions for you. Review in Admin → Work → Needs details." % (n, q))


# ---------------------------------------------------------------------------- watch (launchd)
PLIST = os.path.expanduser("~/Library/LaunchAgents/com.superdupercorp.sdc.plist")

def watch(folder, every):
    if sys.platform != "darwin":
        die("Background sync uses macOS launchd. Run `sdc sync FOLDER` on a schedule instead.")
    folder = os.path.abspath(os.path.expanduser(folder))
    os.makedirs(folder, exist_ok=True)
    me = os.path.join(HOME, "sdc.py")
    if os.path.abspath(__file__) != me:
        shutil.copy2(os.path.abspath(__file__), me)
    plist = """<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.superdupercorp.sdc</string>
  <key>ProgramArguments</key><array><string>/usr/bin/python3</string><string>%s</string><string>sync</string><string>%s</string><string>--quiet</string></array>
  <key>StartInterval</key><integer>%d</integer>
  <key>RunAtLoad</key><true/>
  <key>StandardOutPath</key><string>%s/watch.log</string>
  <key>StandardErrorPath</key><string>%s/watch.log</string>
</dict></plist>
""" % (html.escape(me), html.escape(folder), every * 60, HOME, HOME)
    os.makedirs(os.path.dirname(PLIST), exist_ok=True)
    subprocess.run(["launchctl", "unload", PLIST], capture_output=True)
    with open(PLIST, "w") as f:
        f.write(plist)
    subprocess.run(["launchctl", "load", "-w", PLIST], check=False)
    say("Watching %s every %d minutes." % (folder, every))
    say("Drop a folder per project in there. New files are sent as drafts automatically. Log: %s/watch.log" % HOME)

def unwatch():
    if os.path.exists(PLIST):
        subprocess.run(["launchctl", "unload", "-w", PLIST], capture_output=True)
        os.remove(PLIST)
    say("Background sync stopped.")


# ---------------------------------------------------------------------------- main
def main():
    ap = argparse.ArgumentParser(prog="sdc", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sp = ap.add_subparsers(dest="cmd")
    sp.add_parser("setup")
    sp.add_parser("status")
    s = sp.add_parser("scan"); s.add_argument("path"); s.add_argument("-o", "--out"); s.add_argument("--max", type=int, default=20)
    s = sp.add_parser("upload"); s.add_argument("plan"); s.add_argument("--max", type=int, default=20)
    s = sp.add_parser("sync"); s.add_argument("path"); s.add_argument("--max", type=int, default=20); s.add_argument("--quiet", action="store_true")
    s = sp.add_parser("watch"); s.add_argument("path"); s.add_argument("--every", type=int, default=15)
    sp.add_parser("unwatch")
    s = sp.add_parser("web"); s.add_argument("urls", nargs="+"); s.add_argument("--category"); s.add_argument("--year", type=int)
    s = sp.add_parser("instagram"); s.add_argument("folder"); s.add_argument("--since", type=int); s.add_argument("--category", default="personal")
    s = sp.add_parser("wordpress"); s.add_argument("url"); s.add_argument("--type"); s.add_argument("--dry-run", action="store_true"); s.add_argument("--max", type=int, default=24)
    s = sp.add_parser("drafts"); s.add_argument("action", choices=["pull", "push"]); s.add_argument("folder")
    a = ap.parse_args()

    if a.cmd == "setup":
        os.makedirs(HOME, exist_ok=True)
        old = json.load(open(CONFIG)) if os.path.exists(CONFIG) else {}
        site = input("Site address [%s]: " % old.get("site", "https://superdupercorp.com")).strip() or old.get("site", "https://superdupercorp.com")
        if not site.startswith("http"):
            site = "https://" + site
        key = input("Uploader key (Admin → Site copy → Uploader keys): ").strip() or old.get("key", "")
        cfg = {"site": site.rstrip("/"), "key": key}
        try:
            r = Site(cfg).get("ping")
        except RuntimeError as e:
            die("Couldn't connect: %s" % e)
        with open(CONFIG, "w") as f:
            json.dump(cfg, f)
        os.chmod(CONFIG, 0o600)
        say("✓ Connected to %s. Host upload limit: %s per file." % (r.get("site"), r["limits"]["upload_max_filesize"]))
        return
    if a.cmd == "scan":
        plan = scan(a.path, a.max)
        out = a.out or os.path.join(os.path.expanduser("~/Desktop"), "sdc-plan-%s.csv" % time.strftime("%Y%m%d-%H%M"))
        write_plan(plan, out)
        files = sum(p["files_to_send"] for p in plan)
        say("Found %d projects (%d files to send). Plan saved to:\n  %s" % (len(plan), files, out))
        say("Open it in Numbers or Excel: fix titles/years/categories, set include=no for anything to skip, save as CSV.")
        say("Then run:  sdc upload \"%s\"" % out)
        return
    if a.cmd == "status":
        c = db()
        say("Projects sent: %d" % c.execute("select count(*) from projects").fetchone()[0])
        for r, n in c.execute("select result, count(*) from files group by substr(result,1,7)"):
            say("  files %-30s %d" % (r[:30], n))
        say("Background sync: %s" % ("on" if os.path.exists(PLIST) else "off"))
        return
    if a.cmd == "unwatch":
        return unwatch()
    if a.cmd == "watch":
        load_config()
        return watch(a.path, a.every)
    if a.cmd is None:
        ap.print_help()
        return

    if a.cmd == "wordpress" and a.dry_run:
        return import_wordpress(None, a.url, a.type, True)
    site = Site(load_config())
    if a.cmd == "upload":
        plan = read_plan(a.plan, a.max)
        say("Uploading %d projects as drafts…" % len(plan))
        run_upload(site, plan)
    elif a.cmd == "sync":
        root = os.path.abspath(os.path.expanduser(a.path))
        if not os.path.isdir(root):
            die("Folder not found: " + root)
        plan = []
        for sub in sorted(os.listdir(root)):
            full = os.path.join(root, sub)
            if not os.path.isdir(full) or sub.startswith(".") or sub.lower() in SKIP_DIRS:
                continue
            found = scan(full, 10 ** 6)  # each top-level folder = one project, whatever is inside it
            files = [f for p in found for f in p["files"]]
            if not files:
                continue
            client, title = parse_name(sub)
            years = [int(y) for y in YEAR_RE.findall(sub)]
            cat, disc = guess(sub, files)
            plan.append({"folder": full, "title": title, "client": client, "category": cat, "disciplines": ", ".join(disc),
                         "year": years[-1] if years else file_year(files),
                         "files": sorted(files, key=lambda f: -score(f, full))[:a.max]})
        if a.quiet and not plan:
            return
        if a.quiet:
            say(time.strftime("%F %T") + " sync: %d project folders" % len(plan))
        run_upload(site, plan, quiet=a.quiet)
    elif a.cmd == "web":
        import_web(site, a.urls, a.category, a.year)
    elif a.cmd == "instagram":
        import_instagram(site, a.folder, a.since, a.category)
    elif a.cmd == "wordpress":
        import_wordpress(site, a.url, a.type, a.dry_run, a.max)
    elif a.cmd == "drafts":
        (drafts_pull if a.action == "pull" else drafts_push)(site, a.folder)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        say("\nStopped. Run the same command again to pick up where you left off.")
