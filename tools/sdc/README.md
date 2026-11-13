# sdc: the SUPER DUPER CORP uploader

`sdc` sends your work from your Mac (archive drive, folders, old websites, Instagram) to the site.
**Everything arrives as a hidden draft.** Nothing goes live until you publish it in the admin.

## 1. Install (once)

1. On the site, go to **Admin → Site copy → Uploader keys**, name the key (e.g. "Studio iMac"), and click **+ New key**. Copy the key it shows; it is only shown once.
2. On your Mac, double-click **`Install sdc.command`** in this folder. If macOS says it's from an unidentified developer, right-click it and choose **Open**.
3. When asked, enter your site address (`https://superdupercorp.com`) and paste the key.

## 2. The old archive drive

Plug in or mount the drive, then in Terminal:

```bash
sdc scan "/Volumes/ARCHIVE"
```

This doesn't upload anything. It looks through the drive and saves a plan to your Desktop (`sdc-plan-….csv`):
one row per project, with a guessed **title, client, year and category**.

- Open the plan in **Numbers** or **Excel**. Fix anything that's wrong, and type `no` in the *include* column for anything to leave out.
- Save it as CSV (in Numbers: File → Export To → CSV, keeping the same name).

Then:

```bash
sdc upload ~/Desktop/sdc-plan-XXXX.csv
```

It's safe to stop (Ctrl-C) and run the same command again. It picks up where it left off and never sends a file twice.

### How it reads your folders

- A folder that contains images or videos is a **project**.
- Folders named *Finals, Exports, JPG, Hi-res, Selects, Web, Print, v2…* are treated as part of the project above them.
- Folders named *WIP, Raw, Old, Source, Backup, Links, Fonts, Trash…* are skipped.
- Folder names like `2014 - Nike - Air Max Launch` become **year 2014, client Nike, title Air Max Launch**.
- Design files (.ai, .indd, .sketch, .fig, camera RAW…) are skipped. Flat exports (JPG/PNG) are preferred over layered PSD/TIFF.
- HEIC, TIFF and PSD are converted to JPEG on your Mac, and big images are resized, so uploads fit your host.
- Up to 20 of the best files per project are sent (`--max 40` for more). Files in *Finals/Exports* rank first.

## 3. Current work: a folder that syncs itself

```bash
sdc watch "~/SDC Inbox"
```

Every 15 minutes your Mac checks `~/SDC Inbox`. Make **one folder per project** inside it (name it `Client - Project`),
drop images in, and they show up as a draft. Files added later to that folder are added to the same draft,
until you publish it; after that, add new images in the admin. To stop: `sdc unwatch`.

> If the folder is on an external drive, macOS may ask to allow access. Click **Allow**. If it never asks: System Settings →
> Privacy & Security → Full Disk Access → add `/usr/bin/python3`.

## 4. Behance, old websites, Instagram

```bash
sdc web https://www.behance.net/gallery/123456/Project-Name
sdc web https://old-site.com/work/project another-url …
```

Each page becomes a draft with its images, title, description and year. (Pages that only load images with JavaScript
won't work. Use the Wayback Machine copy of the page instead, `https://web.archive.org/web/2015/old-site.com/…`.)

**WordPress** (e.g. your old site): `sdc wordpress https://superdupercorp.com` imports every portfolio item
(or post) with its write-up, year, images and video link. Add `--dry-run` first to see what it would import.

**Instagram** doesn't allow page imports. Use your data export instead:
Instagram → Settings → Accounts Center → Your information and permissions → **Download your information** →
choose **JSON** and include **Posts**. Unzip it, then:

```bash
sdc instagram ~/Downloads/instagram-export --since 2016
```

Each post becomes a draft (caption → write-up, date → year). Delete the ones that aren't portfolio pieces.

## 5. Let Claude write the details

```bash
sdc drafts pull ~/Desktop/sdc-drafts
```

This downloads every draft that still needs details: images plus a `details.json` for each.
Open Claude (Claude Code, or Claude desktop with folder access) **in that folder** and say:

> Follow HOW-TO-FILL.md

Claude looks at the images and fills in title, year, category, a one-line summary and a short write-up.
It's told never to invent clients, results or awards; when unsure it leaves a question for you instead. Then:

```bash
sdc drafts push ~/Desktop/sdc-drafts
```

The drafts now show **"Claude draft: check it"** in Admin → Work → **Needs details**. Read them, fix anything, publish.

## Handy

```bash
sdc status          # how much has been sent
sdc --help          # every command
```

If your Mac is lost, revoke its key in **Admin → Site copy → Uploader keys**.
