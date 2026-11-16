# Tribal Weave Archive of the Philippines

## Project Description

The Tribal Weave Archive of the Philippines is a web application that preserves and presents Philippine Indigenous textile traditions: woven textiles, bark cloth and plaited fiber. Visitors can browse and search the archive by weave, tribe, region and craft medium. Registered members submit weaving records with documentation and photos, and administrators review each submission before it is published.

Built with HTML, CSS (Tailwind CDN), vanilla JavaScript (fetch API), PHP, and an SQLite database accessed through PDO. Passwords are stored hashed, never as plain text.

## Group Members

**Group 6:** 
1. Banania, Ian 
2. Borromeo, Nicole Irrah
3. Castillo, Ashley
4. Cenon, Symeon
5. Macaubos, Carl Andrae

## How to Run the Project

**Requirements:** PHP 8.0 or newer with `pdo_sqlite`, `mbstring` and `fileinfo` (all included in XAMPP). Internet access is needed for the Tailwind, icon and font CDNs.

1. Copy the project folder into `C:\xampp\htdocs\`.
2. Start Apache in the XAMPP control panel.
3. Open `http://localhost/<folder-name>/`.

**Demo accounts** (change these passwords for any real use):

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `Admin@123` |
| Member | `member` | `Member@123` |

To rebuild the database from scratch: `php setup.php --reset`.
Password-reset emails are simulated and written to `storage/mail_outbox.log`.
