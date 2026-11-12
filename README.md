# SUPER DUPER CORP — website

A portfolio and archive site for SUPER DUPER CORP LLC (MaxP Goodwin).
It's plain PHP with no database. Everything is stored in `/data` as JSON files and media goes in `/uploads`. It runs on any cPanel / shared host with PHP 8.0 or newer.

## Put it live (cPanel, ~10 minutes)

1. **Back up the current site.** In cPanel → File Manager, open `public_html`, select everything, click **Compress** and download the zip.
2. **Check PHP.** cPanel → *Select PHP Version* (or *MultiPHP Manager*). Pick **8.1 or newer** and make sure **gd** and **fileinfo** are ticked.
3. **Upload.** In File Manager, open `public_html`, upload `superdupercorp-site.zip` and **Extract** it there. Turn on *Settings → Show Hidden Files* and confirm the `.htaccess` files came through (root, `data/`, `uploads/`).
4. **Set your password.** Visit `https://superdupercorp.com/admin`. The page creates a setup code in `public_html/data/SETUP-CODE.txt`. Open that file in File Manager, copy the code into the form, and choose a password. The code file deletes itself once you're done.
5. **Force HTTPS** (optional): in `.htaccess`, remove the `#` from the two lines under “Force HTTPS”.

## Adding work

- **Admin → + New**: one project. Add a title, kind of work, year, the story, a cover, and gallery images or MP4 video. You can also paste a YouTube or Vimeo link.
- **Admin → Bulk import**: for the archive. Upload a batch of files and each one becomes a hidden **draft** (or all go into one project). Fill them in later.
- **Admin → Work**: tick projects, then publish, unpublish, feature on the home page, or delete.
- **Admin → Site copy**: every other word on the site (tagline, studio statement, bio, services, clients, timeline, email, socials) plus the founder photo.
- **Admin → Inbox**: contact form messages, also emailed to the address in Site copy.

Images are resized automatically: 2400px for full size and 1000px for thumbnails, saved as WebP.

### Upload limits
Shared hosts often cap uploads at 2–64 MB. If big files fail, go to cPanel → *Select PHP Version → Options* (or *MultiPHP INI Editor*) and raise `upload_max_filesize` and `post_max_size` (e.g. 128M), and `max_file_uploads` (e.g. 50).

## Backups
All your content is in two folders: **`data/`** (text) and **`uploads/`** (media). Download both now and then.

## Local preview (for developers)
```
php -S localhost:8000 dev-router.php
```
