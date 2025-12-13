-- DROP & CREATE (optional, if fresh start)
DROP DATABASE IF EXISTS digital_library;
CREATE DATABASE digital_library
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE digital_library;

-- USERS
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- BOOKS
CREATE TABLE books (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  author VARCHAR(100) NOT NULL,
  category VARCHAR(100) NOT NULL,
  description TEXT,
  total_copies INT NOT NULL DEFAULT 1,
  available_copies INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- BORROWS (ISSUE / RETURN / FINE)
CREATE TABLE borrows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  book_id INT NOT NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_date DATE NOT NULL,
  returned_at DATETIME NULL,
  status ENUM('issued','returned') NOT NULL DEFAULT 'issued',
  fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
);

-- DEFAULT ADMIN
INSERT INTO users(full_name,email,password,role)
VALUES ('Admin','admin@gmail.com', SHA2('admin123',256),'admin');