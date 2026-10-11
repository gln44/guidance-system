# Guidance Counseling System

A website where the guidance office records counseling sessions with students.
Only counselors with an account can log in.

Built with PHP, MySQL/MariaDB, HTML, CSS, and a little JavaScript. No frameworks,
so every part of the code can be read and explained.

## Setting it up with XAMPP

1. Copy the `guidance-system` folder into `C:\xampp\htdocs\`.
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Go to `http://localhost/phpmyadmin`, open the **Import** tab, choose
   `database/schema.sql`, and click **Import**. This creates the `guidance_db` database.
4. Open `config/config.php` and:
   - set `APP_TIMEZONE` to your timezone so dates are correct
     (list: https://www.php.net/manual/en/timezones.php)
   - set `SCHOOL_NAME` to your school
   - change `DB_USER` / `DB_PASS` if your MySQL is not the XAMPP default (`root`, no password)
5. Open `http://localhost/guidance-system`. The first visit opens the setup page,
   where you create the administrator account. After that, setup locks itself.
6. Log in. As the administrator, go to **Counselor accounts** to add the other counselors.

## Putting it online (free hosting)

1. Create a free account at a PHP/MySQL host such as InfinityFree and create a hosting account (website).
2. In the control panel, create a MySQL database. Note the **hostname, database name, username, and password** it shows.
3. Open that database in the host's phpMyAdmin and import `database/schema-online.sql`
   (not `schema.sql`; hosts don't allow creating databases through SQL).
4. In `config/config.php`, replace `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` with the values from step 2.
5. Upload the **contents** of the `guidance-system` folder (not the folder itself) into the host's `htdocs` folder.
6. Open your site's address and create the administrator account on the setup page.

Free hosting is fine for testing and class demos. Real counseling records are sensitive
personal information, so for actual use the school should host it on paid hosting or its
own server with HTTPS and regular backups.

## Grade and section buttons

The **Students** page has a button for each grade level. Click one to list only that grade,
then click a section to narrow it down. The search box also understands grades and sections:
try `grade 7`, `g11 stem`, `10`, or `grade 7 Rosal`.

The **Sessions** page has the same grade and section buttons, and they work together with the
type, status, date, and "My sessions only" filters. Sessions are grouped by the student's
current grade and section.

Administrators set each grade's sections on the **Sections** page (Students > Manage sections).
Sections found on student records (for example after an import) can be added to the list with
one click. Renaming a section also updates the students in it. The `sections` table is created
automatically the first time it's needed, so existing installations don't need to import anything.

## Printing

- **Students > Print list** prints every student matching the current grade, section, and search
  (not just the 20 on screen), sorted by grade, section, and name.
- **A student's page > Print** prints their details and counseling history.
- **A session's page > Print** prints that one session.

Every printout has the school logo and name, is marked Confidential, and ends with a signature
line and the counselor's printed name: session records are signed by the counselor who conducted
the session ("Counseled by"); lists and student records by the counselor printing them
("Prepared by"). An administrator sets up to three signatures on **Counselors > Printout signatures**, each
with a heading above the line (e.g. "Noted by"), a printed name, and a title under the name.
Signature 1 fills in the counselor automatically when its heading or name is left blank.
A checklist on the same page chooses which signatures go on each kind of printout (student record,
session record, student list).
Print pages open the print dialog automatically; choose "Save as PDF" to make a PDF instead.

## Phones

The system adjusts to phone screens. To see the full desktop layout on a phone, tap
**Switch to desktop view** in the menu (or use the browser's "Desktop site" option);
**Switch to mobile view** goes back.

## Importing students from a spreadsheet

On the **Students** page, click **Import from file**.

1. Make sure the first row of your spreadsheet has column names. Required: Student No, Last Name,
   First Name. Optional: Middle Name, Sex, Birthdate, Grade Level, Strand, Section, Contact No,
   Email, Address, Guardian Name, Guardian Contact. Order doesn't matter; extra columns are ignored.
   A blank template is in `assets/templates/students-template.csv`.
2. In Excel, use File > Save As > **CSV UTF-8 (Comma delimited)**.
3. Upload it, check the preview, and click **Import**. Nothing is saved before you confirm.
   Rows with problems are listed so you can fix them; students already in the system are skipped
   (or updated, if you choose that option).

## Who can do what

| Action | Counselor | Administrator |
|---|---|---|
| Add and edit students | Yes | Yes |
| Record sessions, view all sessions | Yes | Yes |
| Edit or delete a session | Only their own | Any |
| Delete a student record (only if no sessions) | No | Yes |
| Add, deactivate, reset counselor accounts | No | Yes |

## Files

| File | What it does |
|---|---|
| `config/config.php` | Database login, school name, timezone, dropdown choices |
| `includes/bootstrap.php` | Loaded by every page: session, database, login checks, security helpers |
| `includes/header.php`, `footer.php` | Shared sidebar and page layout |
| `setup.php` | One-time page that creates the first administrator |
| `login.php`, `logout.php` | Logging in and out |
| `dashboard.php` | Follow-ups due, concerns this month, recent sessions |
| `students.php` | Student list and search |
| `students_import.php` | Import many students at once from a CSV file |
| `sections.php` | Set the sections for each grade level (admin only) |
| `signatures.php` | Printout signatures (admin only) |
| `view_mode.php` | Switches a phone between mobile and desktop view |
| `includes/print.php`, `assets/css/print.css` | Layout for printed lists |
| `student_form.php` | Add or edit a student |
| `student_view.php` | Student profile and counseling history |
| `student_delete.php` | Deletes a student with no sessions (admin only) |
| `sessions.php` | All sessions, with search and filters |
| `session_form.php` | Record or edit a session |
| `student_lookup.php` | Finds students as you type in the session form's Student box |
| `session_view.php` | Full session record, printable |
| `session_delete.php` | Deletes a session |
| `counselors.php` | Manage counselor accounts (admin only) |
| `change_password.php` | Change your own password |
| `database/schema.sql` | Creates the database and its three tables (for XAMPP) |
| `database/schema-online.sql` | Creates only the three tables (for online hosting) |
| `assets/css/style.css`, `assets/js/app.js` | Styling and small page behaviors |
| `assets/img/logo.svg` | The default logo |

## Database

- **counselors**: the accounts that can log in (passwords are stored hashed, never as plain text)
- **students**: student records
- **counseling_sessions**: one row per session, linked to a student and to the counselor who recorded it

## Security features

- Passwords hashed with `password_hash()` and checked with `password_verify()`
- All database queries use prepared statements (protects against SQL injection)
- All output is escaped with `htmlspecialchars()` (protects against XSS)
- Every form has a CSRF token
- Session ID is regenerated at login; cookies are HTTP-only
- Automatic logout after 30 minutes of inactivity (`SESSION_TIMEOUT` in the config)
- Deactivated accounts are logged out on their next page load

## Using your school's logo

The system comes with a maroon and gold compass logo (`assets/img/logo.svg`).
It appears in the sidebar, on the login page, in the browser tab, and at the
top of printed session records. To use your school's logo instead:

1. Copy your logo image (PNG, JPG, or SVG) into `assets/img/`, for example `school-logo.png`.
2. Open `config/config.php` and change `LOGO_PATH` to `'assets/img/school-logo.png'`.
3. Save and refresh the page.

A square image with a transparent background looks best.

## Changing the colors

All colors are set at the top of `assets/css/style.css` under `:root`.
Change `--brand` (maroon), `--brand-d` (dark maroon for the sidebar), and
`--gold` to restyle the whole system.

## Icons

Button and menu icons are drawn inline by the `icon()` function in
`includes/bootstrap.php`, so they work without internet. Their shapes are
based on Lucide (https://lucide.dev, ISC license).

## Changing the dropdown choices

Session types, referral sources, and grade levels (Grade 7 to 12) are lists in `config/config.php`.
Add or remove items there and the forms update automatically.
