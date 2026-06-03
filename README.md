# 📚 School Library Management System
**A full-stack web application built with PHP, MySQL, and Bootstrap 5**

---

## 📋 Description

A role-based School Library Management System built with procedural PHP and MySQL. It supports four user roles — Admin, Librarian, Student, and Teacher — and covers the full library workflow: book management, borrowing and returns, fine calculation, reservations with a session-based cart, announcements, and CSV reports. Features cookie-based login memory and a complete activity audit trail.

---

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8 (Procedural) |
| Database | MySQL via MySQLi |
| Frontend | Bootstrap 5, Bootstrap Icons |
| Typography | Google Fonts (Playfair Display, DM Sans) |
| Server | Apache (XAMPP) |

---

## 👥 User Roles & Permissions

| Feature | Admin | Librarian | Student | Teacher |
|---|:---:|:---:|:---:|:---:|
| Manage Books (Add/Edit/Delete) | ✅ | ✅ | ❌ | ❌ |
| View Book Details | ✅ | ✅ | ✅ | ✅ |
| Manage Users | ✅ | ❌ | ❌ | ❌ |
| Issue / Return Books | ✅ | ✅ | ❌ | ❌ |
| View Own Borrows | ✅ | ✅ | ✅ | ✅ |
| Reservation Cart | ❌ | ❌ | ✅ | ✅ |
| Approve Reservations | ✅ | ✅ | ❌ | ❌ |
| Manage Fines | ✅ | ✅ | ❌ | ❌ |
| View Reports + CSV Export | ✅ | ✅ | ❌ | ❌ |
| Activity Logs | ✅ | ❌ | ❌ | ❌ |
| Post Announcements | ✅ | ✅ | ❌ | ❌ |

---

## 📁 Project Structure

```
school_library/
├── index.php                  ← Login page (cookie remember me)
├── dashboard.php              ← Role-based dashboard
├── logout.php                 ← Session destroy + redirect
├── profile.php                ← Edit profile + change password
│
├── config/
│   ├── db.php                 ← DB connection, constants, timezone
│   ├── session.php            ← Auth functions, flash messages
│   └── LibraryHelper.php      ← OOP demo classes
│
├── includes/
│   └── navbar.php             ← Role-aware navigation + cart badge
│
├── books/
│   ├── index.php              ← Catalog with search and filter
│   ├── add.php                ← Add book + cover upload
│   ├── edit.php               ← Edit book
│   ├── view.php               ← Book detail + borrow history
│   └── delete.php             ← Safe delete handler
│
├── users/
│   ├── index.php              ← User list + role summary
│   ├── add.php                ← Add user + photo upload
│   ├── edit.php               ← Edit user + optional password reset
│   ├── toggle_status.php      ← Activate / deactivate user
│   └── delete.php             ← Safe delete handler
│
├── borrow/
│   ├── index.php              ← All borrow records
│   ├── issue.php              ← Issue book (transaction-safe)
│   ├── return.php             ← Return book + live fine calculator
│   └── my_borrows.php         ← Personal borrow history (student/teacher)
│
├── reservations/
│   ├── index.php              ← All reservations with status tabs
│   ├── cart.php               ← Session-based reservation cart
│   ├── create.php             ← Single book reservation form
│   ├── approve.php            ← Approve reservation
│   ├── cancel.php             ← Cancel reservation
│   └── fulfill.php            ← Convert reservation to borrow record
│
├── reports/
│   ├── borrows.php            ← Borrow history + CSV export
│   ├── overdue.php            ← Overdue books + CSV export
│   ├── fines.php              ← Fines management + CSV export
│   └── activity.php          ← Activity audit logs + CSV export
│
├── announcements/
│   └── index.php              ← Post and view announcements
│
├── uploads/
│   ├── books/                 ← Book cover images
│   └── profiles/              ← User profile pictures
│
├── assets/img/
│   ├── default.png            ← Default profile picture
│   └── default_book.png       ← Default book cover
│
└── database/
    └── school_library.sql     ← Full schema + seed data
```

---

## 🔑 Key Features

### Authentication & Security
- Secure login with `password_hash()` / `password_verify()`
- Cookie-based "Remember my School ID" (7-day expiry)
- Role-based access control on every page
- Session-based authentication with `session_destroy()` on logout
- All DB queries use MySQLi prepared statements (SQL injection safe)
- Input sanitized with `htmlspecialchars()` + `trim()`

### Books Module
- Full CRUD with cover image upload (JPG/PNG/WebP, max 2MB)
- ISBN validation (10 or 13 digits)
- Search by title, author, or ISBN
- Filter by category and status
- Availability progress bar per book
- Borrow history visible to admin/librarian

### Users Module
- Four roles: Admin, Librarian, Student, Teacher
- Profile picture upload with live preview
- Password strength meter + match checker
- Activate / deactivate accounts
- Prevents deletion of users with active borrows

### Borrowing & Returns
- Book issuance wrapped in MySQL transaction
- Atomic available_copies decrement with affected_rows guard
- LEAST() cap on return to prevent copies exceeding total
- Live fine calculator (JavaScript) on return page
- Auto-marks overdue records on page load
- Fine records auto-inserted on return

### Reservations
- Session-based reservation cart (like a shopping cart)
- Cart badge counter in navbar
- Add to cart directly from book detail page
- Per-item expiry date (up to 14 days)
- Workflow: Pending → Approved → Fulfilled
- Fulfilled reservation auto-creates a borrow record

### Reports & CSV Export
- Borrow History — filterable by date, role, status
- Overdue Books — color-coded urgency levels
- Fines Management — mark paid / waive (admin only)
- Activity Logs — full audit trail with IP address
- All four reports have CSV export and print support

### Announcements
- Audience targeting: Everyone / Students Only / Teachers Only
- Feed-style layout with color-coded author role borders

---

## 🐘 PHP Features Demonstrated

| Feature | Location |
|---|---|
| Embedding PHP in HTML | Every .php file |
| Variables (all data types) | Throughout |
| Operators (arithmetic, logical, ternary, spread) | return.php, session.php, index.php |
| if / elseif / switch | dashboard.php, LibraryHelper.php |
| while, for, foreach, do...while | All list pages, reports/borrows.php |
| Building functions | config/session.php |
| Event-driven PHP (GET/POST/FILES) | add.php, issue.php, delete.php |
| PHP extensions (MySQLi, Session, PCRE, Filter, GD) | db.php, session.php, add.php |
| Text functions (substr, strlen, ucfirst, nl2br, etc.) | session.php, books/index.php |
| Testing string values (empty, isset, preg_match, filter_var) | users/add.php, books/add.php |
| Date and Time functions | dashboard.php, return.php, db.php |
| Image handling (upload, validate, move, delete) | books/add.php, users/add.php, profile.php |
| Magic methods (__construct, __get, __set, __toString, __clone) | LibraryHelper.php |
| Loading classes (require_once, spl_autoload_register) | Every file, LibraryHelper.php |
| Extending classes (extends, parent::) | LibraryHelper.php |
| Cookies (setcookie, $_COOKIE) | index.php |
| Sessions ($_SESSION, session_start, session_destroy) | session.php, cart.php |
| OOP (class, public, private, $this) | LibraryHelper.php |
| Transactions (begin_transaction, commit, rollback) | issue.php, return.php |
| CSV export (fputcsv) | All reports |

---

## 🗃️ Database Tables

| Table | Purpose |
|---|---|
| users | All user accounts and roles |
| books | Book catalog |
| categories | Book categories |
| borrow_records | All borrow transactions |
| reservations | Book reservation requests |
| fines | Overdue fines per borrow record |
| announcements | Library announcements |
| activity_logs | Full system audit trail |

---

## ⚠️ Known Considerations

- Book available_copies can be resynced anytime by running this SQL:
```sql
UPDATE books b
SET b.available_copies = b.total_copies - (
    SELECT COUNT(*) FROM borrow_records br
    WHERE br.book_id = b.id
    AND br.status IN ('borrowed', 'overdue')
);
```

- Session timeout follows session.gc_maxlifetime in php.ini (XAMPP default: 24 minutes). Set a custom value in config/db.php:
```php
ini_set('session.gc_maxlifetime', 7200); // 2 hours
session_set_cookie_params(7200);
```

- The uploads/ folders must be writable by Apache.
- Delete sessioninfo.php if you created it during testing.

---

## 👨‍💻 Built With

- **PHP 8** — procedural style with OOP demonstrations
- **MySQLi** — prepared statements throughout
- **Bootstrap 5.3** — responsive UI
- **Bootstrap Icons 1.11** — icon set
- **Google Fonts** — Playfair Display + DM Sans

---

*School Library Management System — Final Project*
