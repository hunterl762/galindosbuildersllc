# Galindos Builders LLC

Original PHP/HTML/CSS/JavaScript construction company website with a project-forward presentation inspired by modern commercial contractor websites.

## Requirements
- PHP 8.1+
- Apache or Nginx
- PHP sessions and Fileinfo enabled
- `data/` and `uploads/projects/` writable by PHP

## Install
1. Deploy the repository into the PHP web root.
2. Make `data/` and `uploads/projects/` writable by the web-server account.
3. Visit `/admin/setup.php` once and create the first administrator.
4. Sign in at `/admin/`.
5. Add custom job names, locations, categories, descriptions and multiple photos.
6. Manage custom navigation tabs from the same dashboard.

The site intentionally does not ship with a default password. The initial admin password must be at least 12 characters and is stored using PHP `password_hash()`.

## Content storage
The site uses JSON files under `data/` and stores uploaded project photos under `uploads/projects/`, so MySQL is not required. On Apache, `data/.htaccess` blocks direct web access to stored JSON. For Nginx, add an equivalent deny rule for `/data/`.

## Production notes
- Serve the site over HTTPS.
- Back up `data/` and `uploads/projects/`.
- Set sensible PHP upload limits if uploading large jobsite photo sets.
- Replace the included original placeholder hero artwork with a licensed Galindos Builders project photo when ready.
