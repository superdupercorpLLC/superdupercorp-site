# SUPER DUPER CORP — website

A portfolio and archive site for SUPER DUPER CORP LLC (MaxP Goodwin).
It's plain PHP with no database. Everything is stored in `/data` as JSON files and media goes in `/uploads`. It runs on any cPanel / shared host with PHP 8.0 or newer.

## Put it live (Flashcloud shared hosting / cPanel, ~15 minutes)

> **This account also hosts client sites. Read step 1 before touching anything.**

1. **Find where every site lives.** In cPanel, open **Domains**. The *Document Root* column shows each site's folder.
   Note any client site whose folder is **inside** `public_html` (e.g. `public_html/clientsite.com`).
   Those folders must not be moved, renamed or deleted in the steps below.
2. **Back up.** In File Manager, right-click `public_html` → **Compress** → download the zip.
3. **Set aside the old superdupercorp.com files** (only those, not client folders): make a folder
   `public_html/_old-site` and move the old site's own files into it.
4. **Check PHP.** cPanel → *MultiPHP Manager*: set superdupercorp.com to **PHP 8.1 or newer**.
   In *Select PHP Version → Extensions*, make sure **gd** and **fileinfo** are ticked.
   In *MultiPHP INI Editor* (choose superdupercorp.com), set `upload_max_filesize` and `post_max_size` to **256M**
   and `max_file_uploads` to **50**. On a per-domain basis this won't affect client sites.
5. **Upload.** In `public_html`, upload `superdupercorp-site.zip` and **Extract** it. Turn on *Settings → Show Hidden
   Files* and confirm the `.htaccess` files came through. (The zip contains no folders that clash with client sites.)
6. **Set your password.** Visit `https://superdupercorp.com/admin`. Open `public_html/data/SETUP-CODE.txt` in File Manager,
   enter the code, and choose a password.
7. **HTTPS.** Once `https://superdupercorp.com` loads with a padlock (Flashcloud AutoSSL), remove the `#` from the two
   lines under "Force HTTPS" in `.htaccess`.
8. **Check the client sites still load.** Open each one in your browser.

### Space and video
The plan has 100 GB. Images are resized to web size (typically 0.2–0.8 MB each), so even tens of thousands of images fit.
Long or high-res **videos** are better on Vimeo or YouTube: paste the link into the project's *Video link* field.
Keep MP4 uploads for short clips.

## Adding work

- **Admin → + New**: one project. Add a title, kind of work, year, the story, a cover, and gallery images or MP4 video. You can also paste a YouTube or Vimeo link.
- **Admin → Bulk import**: for the archive. Upload a batch of files and each one becomes a hidden **draft** (or all go into one project). Fill them in later.
- **Admin → Work**: tick projects, then publish, unpublish, feature on the home page, or delete.
- **Admin → Site copy**: every other word on the site (tagline, studio statement, bio, services, clients, timeline, email, socials) plus the founder photo.
- **Admin → Inbox**: contact form messages, also emailed to the address in Site copy.

Images are resized automatically: 2400px for full size and 1000px for thumbnails, saved as WebP.

### Video
- **YouTube / Vimeo:** in a project, paste links into **Add video links** (one per line). Unlisted Vimeo links and
  YouTube Shorts work. The page shows a poster with a play button; the real player loads only when someone presses
  play (faster pages, no YouTube tracking until then).
- **Video files** (MP4, MOV, WebM): upload them with the images. Tick **Loop silently** for short motion pieces;
  they play muted on a loop, like a GIF.
- Each video has a **shape**: Wide 16:9, Vertical 9:16 (Reels/TikTok), Square, or Portrait 4:5.
- **Show the first video at the top** (on by default) puts the lead video where the cover image would be. Reorder with the # numbers.
- Long or high-quality films are best on Vimeo/YouTube (they stream at the right quality for every device). Upload files
  for short clips and loops.

### Upload limits
Shared hosts often cap uploads at 2–64 MB. If big files fail, go to cPanel → *Select PHP Version → Options* (or *MultiPHP INI Editor*) and raise `upload_max_filesize` and `post_max_size` (e.g. 128M), and `max_file_uploads` (e.g. 50).

## Automatic uploads (archive drive, folders, Behance, Instagram)
See **`tools/sdc/README.md`**. In short: make a key in **Admin → Site copy → Uploader keys**, double-click
`tools/sdc/Install sdc.command` on your Mac, then `sdc scan "/Volumes/ARCHIVE"` → review the plan → `sdc upload plan.csv`.
Everything arrives as a draft; Claude can fill in the details (`sdc drafts pull/push`); you publish.
You don't need to upload the `tools/` folder to your host.

## Security and client data
- **HTTPS only.** Pages redirect to `https://`, the admin refuses plain HTTP, and the uploader API rejects keys sent
  over plain HTTP. HSTS, a Content-Security-Policy and other security headers are sent from PHP (this host ignores
  the header lines in `.htaccess`).
- **Contact messages are encrypted at rest** (libsodium, or AES-256-GCM via OpenSSL). The key lives in its own folder,
  `~/sdc-site-keys/encryption.key`, outside the web root and apart from the data folder, so a copy of the data alone
  can't be read. Older plain-text messages are encrypted automatically the first time the Inbox is opened.
- **Data minimisation.** Only the network part of a sender's IP is stored (e.g. `203.0.113.0`). Messages are
  deleted automatically after 365 days; change this in **Site copy → Privacy** (0 = keep forever). You can also stop
  notification emails from containing the message itself, since email is not encrypted.
- **Admin login.** 12+ character passwords, optional **two-step login** (authenticator app codes) in
  **Site copy → Security**, which we strongly recommend. Sessions end after 2 hours idle or 12 hours total, and changing
  the password signs out every other browser. Failed passwords, setup codes and 2FA codes are rate-limited per IP and
  overall. Lost your phone? Delete the `"totp"` entry from `auth.json` in the data folder.
- **Security log.** Logins, failed attempts, password and 2FA changes, uploader key changes and rejected keys are listed
  under **Site copy → Security**.

## Backups
All your content is in two folders: **`data/`** (text) and **`uploads/`** (media). On the live server, data is kept in
`~/sdc-site-data`. Download both now and then. **Also back up `~/sdc-site-keys/` and keep that copy somewhere separate
from the data backup:** without the key, saved Inbox messages can't be decrypted.

## Local preview (for developers)
```
php -S localhost:8000 dev-router.php
```
