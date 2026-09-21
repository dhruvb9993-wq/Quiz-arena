=============================================================
  QUIZARENA — Multi-School Quiz SaaS Platform
  Installation Guide for Hostinger Shared Hosting
=============================================================

Thank you for choosing QuizArena! This package is a complete,
production-ready PHP + MySQL web application. It installs entirely
through your browser — no coding, no terminal, no SSH, no manual
SQL import required.

-------------------------------------------------------------
  SERVER REQUIREMENTS (Hostinger Shared Hosting supports all)
-------------------------------------------------------------
  • PHP 8.0 or newer (PHP 8.2 recommended — works up to 8.4)
  • MySQL or MariaDB
  • PHP extensions: pdo_mysql, gd, mbstring, json, zip, fileinfo
    (All are enabled by default on Hostinger. You can verify them
     at hPanel → Advanced → PHP Configuration if needed.)

-------------------------------------------------------------
  INSTALLATION STEPS (5 minutes)
-------------------------------------------------------------
  1. OPEN HOSTINGER HPANEL
     Log in at https://hpanel.hostinger.com

  2. OPEN FILE MANAGER
     Click "File Manager" on the left menu.

  3. GO TO public_html
     Double-click "public_html" in the file list
     (or your domain's document root folder).

  4. UPLOAD THIS ZIP FILE
     Click the "Upload" button at the top of File Manager,
     select quizarena.zip, and wait for the upload to finish.

  5. EXTRACT THE ZIP
     After the upload completes, right-click quizarena.zip →
     "Extract" (or select it and click Extract).
     Once extracted, you may delete the zip file.

  6. CREATE A MYSQL DATABASE
     • Go to hPanel → "Databases" → "MySQL Databases".
     • Click "Create New Database".
     • Enter a name (e.g. quizarena_db) and click "Create".
     • The database user is created automatically with the same
       name as the database. Set a password and remember:
         - Database name
         - Database username
         - Database password
       (You do NOT need to create tables — QuizArena does that.)

  7. OPEN YOUR WEBSITE URL
     Visit your domain, e.g.  https://yourdomain.com
     The QuizArena Installation Wizard will open automatically.

  8. ENTER DATABASE DETAILS
     • Step 1 — Server requirements are checked automatically.
     • Step 2 — Enter the Database Host (usually "localhost"),
       Database Name, Database Username and Database Password.
       Click "Test Connection & Continue".
     • Step 3 — Create your first Super Admin account
       (name, username, email, password) and set the site name.
     • Step 4 — Click "Install Now".

  9. INSTALLATION COMPLETES AUTOMATICALLY
     The wizard will automatically:
       ✔ Create all database tables
       ✔ Insert default settings, plans and categories
       ✔ Create your Super Admin account
       ✔ Create required folders
       ✔ Write the secure database configuration
       ✔ Show a success message and link to the Login page

 10. DONE! LOG IN
     Click "Go to Login Page" and sign in with your Super Admin
     credentials. You can now create schools, subscription plans,
     school admins, teachers and students.

-------------------------------------------------------------
  IMPORTANT NOTES
-------------------------------------------------------------
  • The installer runs ONLY ONCE. After a successful install it
    is locked for security.
  • To re-install (for example, after changing your database),
    open File Manager → public_html → app folder and DELETE the
    file  config.php  — then visit install.php again.
  • If you upload the website into a subfolder (e.g.
    public_html/quiz/), QuizArena detects the folder automatically
    and the site will work from  https://yourdomain.com/quiz/

-------------------------------------------------------------
  FOLDER PERMISSIONS
-------------------------------------------------------------
  Hostinger normally sets these automatically. If uploads fail
  (logos, photos, CSV import, certificates), set permissions to
  755 (or 775) on these folders using File Manager → right-click
  → Permissions:
      /public_html/app/logs
      /public_html/assets/uploads
      /public_html/assets/uploads/logos
      /public_html/assets/uploads/photos
      /public_html/assets/uploads/certificates
      /public_html/assets/uploads/temp

-------------------------------------------------------------
  DEFAULT LOGIN
-------------------------------------------------------------
  Use the Super Admin account you created during installation.
  (No default passwords are shipped with the package.)

-------------------------------------------------------------
  QUICK TOUR — WHAT YOU GET
-------------------------------------------------------------
  Roles            Super Admin · School Admin · Teacher · Student
  Schools          Add/edit/suspend schools, school codes, logos,
                   plans, subscriptions and expiry dates.
  Quizzes          MCQ builder (4 options, correct answer,
                   explanation, marks, negative marking, timer,
                   schedule, attempts, entry fee, rewards).
  Wallet           Quiz Coins — entry fees, completion/pass/rank
                   rewards, secure send-by-username transfers,
                   full transaction history.
  Results          Auto-scoring, ranks, points, coins, pass/fail,
                   question-wise analysis.
  Certificates     Auto PDF certificate with unique ID + QR code,
                   public verification page.
  Leaderboard      Today/Week/Month/Year/All-time, school-wise,
                   class-wise, quiz-wise.
  Reports          School/class/student/teacher/quiz-wise with
                   CSV + Excel export.
  Subscriptions    Per-quiz, monthly, yearly and custom plans with
                   manual payment approval by Super Admin.

-------------------------------------------------------------
  SUPPORT / CONTACT
-------------------------------------------------------------
  Questions? Contact the platform administrator or your hosting
  provider. For database help, see Hostinger's MySQL Databases
  guide in hPanel.

-------------------------------------------------------------
  SECURITY NOTE
-------------------------------------------------------------
  • Database credentials are stored ONLY in app/config.php,
    which is blocked from public web access by .htaccess.
  • Passwords are hashed with bcrypt.
  • The app uses prepared statements, CSRF tokens, role-based
    access control, school-level data isolation and login rate
    limiting out of the box.

Enjoy QuizArena! 🎓
