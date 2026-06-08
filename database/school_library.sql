-- ============================================
-- SCHOOL LIBRARY MANAGEMENT SYSTEM
-- Database: school_library
-- ============================================

CREATE DATABASE IF NOT EXISTS school_library;
USE school_library;

-- ============================================
-- USERS TABLE (Admin, Librarian, Student, Teacher)
-- ============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id VARCHAR(20) UNIQUE NOT NULL,         -- e.g. "2024-0001"
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'librarian', 'student', 'teacher') NOT NULL,
    grade_section VARCHAR(50) NULL,                -- for students only
    department VARCHAR(100) NULL,                  -- for teachers only
    profile_pic VARCHAR(255) DEFAULT 'default.png',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================
-- CATEGORIES TABLE
-- ============================================
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- BOOKS TABLE
-- ============================================
CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    isbn VARCHAR(20) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    publisher VARCHAR(150) NULL,
    publication_year YEAR NULL,
    category_id INT NOT NULL,
    total_copies INT DEFAULT 1,
    available_copies INT DEFAULT 1,
    location VARCHAR(100) NULL,                    -- shelf/room location
    cover_image VARCHAR(255) DEFAULT 'default_book.png',
    description TEXT NULL,
    status ENUM('available', 'unavailable') DEFAULT 'available',
    added_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- ============================================
-- BORROWING RECORDS TABLE
-- ============================================
CREATE TABLE borrow_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    issued_by INT NOT NULL,                        -- librarian/admin who approved
    borrow_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE NULL,
    status ENUM('borrowed', 'returned', 'overdue') DEFAULT 'borrowed',
    remarks TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- ============================================
-- FINES TABLE
-- ============================================
CREATE TABLE fines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    borrow_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) DEFAULT 0.00,
    days_overdue INT DEFAULT 0,
    status ENUM('unpaid', 'paid') DEFAULT 'unpaid',
    paid_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrow_id) REFERENCES borrow_records(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);

-- ============================================
-- BOOK RESERVATIONS TABLE
-- ============================================
CREATE TABLE reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    reserved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATE NOT NULL,
    status ENUM('pending', 'approved', 'cancelled', 'fulfilled') DEFAULT 'pending',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
);

-- ============================================
-- ANNOUNCEMENTS TABLE
-- ============================================
CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    posted_by INT NOT NULL,
    target_role ENUM('all', 'student', 'teacher') DEFAULT 'all',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- ============================================
-- ACTIVITY LOGS TABLE
-- ============================================
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(50) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================
-- SEED: DEFAULT CATEGORIES
-- ============================================
INSERT INTO categories (name, description) VALUES
('Fiction', 'Novels, short stories, and fictional works'),
('Non-Fiction', 'Factual and informational books'),
('Science & Technology', 'Books on science, engineering, and technology'),
('Mathematics', 'Mathematics textbooks and references'),
('History', 'Historical books and references'),
('Literature', 'Literary works, poetry, and essays'),
('Reference', 'Dictionaries, encyclopedias, atlases'),
('Periodicals', 'Magazines, journals, and newspapers');

-- ============================================
-- SEED: DEFAULT ADMIN USER
-- Password: Admin@1234 (bcrypt hashed)
-- ============================================
INSERT INTO users (school_id, full_name, email, password, role) VALUES
('ADMIN-001', 'System Administrator', 'admin@schoollibrary.com',
'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');