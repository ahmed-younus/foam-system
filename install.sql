-- Foam Orders Management System
-- Database Schema Installation Script
-- Version 2.0.0

-- Create database (if needed)
CREATE DATABASE IF NOT EXISTS foam_orders CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE foam_orders;

-- Users table (for authentication)
CREATE TABLE IF NOT EXISTS foam_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  last_login DATETIME NULL,
  PRIMARY KEY (id),
  KEY email_idx (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orders table
CREATE TABLE IF NOT EXISTS foam_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  order_number VARCHAR(190) NOT NULL,
  status VARCHAR(50) NOT NULL,
  customer_name VARCHAR(190) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(50) NULL,
  total DECIMAL(18,2) NOT NULL DEFAULT 0,
  currency VARCHAR(10) NOT NULL DEFAULT 'GBP',
  shipping_method VARCHAR(190) NULL,
  priority VARCHAR(50) NULL,
  date_created DATETIME NULL,
  date_modified DATETIME NULL,
  grades VARCHAR(255) NULL,
  depths VARCHAR(255) NULL,
  job_desc TEXT NULL,
  parts_total INT NULL,
  parts_done INT NULL,
  progress_pct TINYINT NULL,
  source VARCHAR(100) NULL,
  billing_address TEXT NULL,
  shipping_address TEXT NULL,
  customer_notes TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY order_id_unique (order_id),
  KEY status_idx (status),
  KEY date_mod_idx (date_modified),
  KEY email_idx (email),
  KEY phone_idx (phone),
  KEY source_idx (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order items table
CREATE TABLE IF NOT EXISTS foam_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  sku VARCHAR(190) NULL,
  name TEXT NULL,
  qty INT NOT NULL DEFAULT 0,
  line_total DECIMAL(18,2) NOT NULL DEFAULT 0,
  meta_json LONGTEXT NULL,
  PRIMARY KEY (id),
  KEY order_id_idx (order_id),
  KEY product_id_idx (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order parts table (for batching)
CREATE TABLE IF NOT EXISTS foam_order_parts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_row_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  item_index INT NULL,
  grade VARCHAR(255) NOT NULL,
  depth_cm INT NULL,
  qty INT NOT NULL DEFAULT 1,
  status ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
  batch_id VARCHAR(64) NULL,
  thumb TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY order_row_id_idx (order_row_id),
  KEY order_id_idx (order_id),
  KEY status_idx (status),
  KEY grade_idx (grade),
  KEY depth_cm_idx (depth_cm),
  KEY batch_id_idx (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Batches table
CREATE TABLE IF NOT EXISTS foam_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id VARCHAR(64) NOT NULL,
  grade VARCHAR(255) NOT NULL,
  depth_cm INT NULL,
  total_qty INT NOT NULL DEFAULT 0,
  status ENUM('open','closed','completed') NOT NULL DEFAULT 'open',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  notes TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY batch_id_unique (batch_id),
  KEY grade_idx (grade),
  KEY depth_cm_idx (depth_cm),
  KEY status_idx (status),
  KEY created_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Waste inventory table
CREATE TABLE IF NOT EXISTS foam_waste (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  grade VARCHAR(255) NOT NULL,
  depth_cm INT NULL,
  qty INT NOT NULL DEFAULT 0,
  location VARCHAR(100) NULL,
  notes TEXT NULL,
  added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY grade_idx (grade),
  KEY depth_cm_idx (depth_cm),
  KEY location_idx (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allocations table (waste to orders)
CREATE TABLE IF NOT EXISTS foam_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  waste_id BIGINT UNSIGNED NOT NULL,
  part_id BIGINT UNSIGNED NOT NULL,
  qty INT NOT NULL DEFAULT 1,
  allocated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY waste_id_idx (waste_id),
  KEY part_id_idx (part_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings table (for app configuration)
CREATE TABLE IF NOT EXISTS foam_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value LONGTEXT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default API secret
INSERT INTO foam_settings (setting_key, setting_value) VALUES
('api_secret', 'change-this-secret-key-for-production')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
