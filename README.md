# Galindos Builders LLC

PHP/HTML/CSS/JavaScript construction website with an administrator CMS.

## Requirements
- PHP 8.1+
- PDO MySQL and Fileinfo PHP extensions
- Apache or Nginx
- `data/` and `uploads/projects/` writable by PHP

## Install
1. Deploy the repository into the PHP web root.
2. Make `data/` and `uploads/projects/` writable.
3. Visit `/admin/setup.php` and create the first administrator.
4. Sign in at `/admin/`.

The CSS/JS URLs are now generated relative to the installation base path, so styling works when the site is hosted at the domain root or inside a subdirectory.

## MySQL / MariaDB
The application automatically uses MariaDB/MySQL when these environment variables are configured:

```
DB_HOST=127.0.0.1
DB_NAME=galindosbuilders
DB_USER=galindos
DB_PASS=change-this-password
```

The configured database/user must already exist. On first connection the app creates the `site_store` table automatically. If database variables are not configured or the connection is unavailable, the CMS falls back to JSON files under `data/`.

## Admin CMS
- Editable homepage hero/about/contact content
- Services management
- Home-page SEO title, meta description and keywords
- Contact / quote request inbox and lead status
- Add/edit/delete projects
- Upload additional project images
- Reorder gallery images
- Delete individual gallery images
- Featured-project control
- Custom navigation tabs

Uploaded project photos remain under `uploads/projects/`; back up this directory along with the database or `data/` directory.
