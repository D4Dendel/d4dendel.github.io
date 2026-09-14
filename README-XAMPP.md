# XAMPP CMS setup

1. Copy this folder into `C:\xampp\htdocs\dyndel-portfolio`.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin and import `database.sql`.
4. Edit `api/config.php` with the MySQL password and a new `ADMIN_PASSWORD_HASH`.
   Set `CONTACT_EMAIL` to the address that should receive contact form inquiries.
5. Generate a password hash from a PHP prompt:

   `php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"`

6. Ensure the `uploads` folder is writable by Apache.
7. Open `http://localhost/dyndel-portfolio/admin.html`.

The admin form accepts image files and stores them in `uploads/`. Projects are stored in MySQL and are available through `api/index.php?action=projects`. The frontend uses the API automatically when served over HTTP; opening HTML files directly with `file://` keeps the existing browser-local fallback.

Security notes:

- Change the default admin hash before deployment.
- Use HTTPS in production.
- Keep `api/config.php` outside public hosting when possible, or deny direct access to it in Apache.
- Back up both the MySQL database and `uploads/`.
- PHP `mail()` also requires SMTP settings in `C:\xampp\php\php.ini` and `C:\xampp\sendmail\sendmail.ini` when testing email through XAMPP.
