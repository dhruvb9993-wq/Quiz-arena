<?php
/**
 * QuizArena — Database schema & seed data.
 * Executed automatically by the browser installer. No manual SQL import needed.
 */

if (!defined('QA_RUNNING') && !defined('QA_INSTALLING')) { exit('Direct access denied'); }

return [

    'tables' => [

        "CREATE TABLE IF NOT EXISTS qa_users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_id INT UNSIGNED NULL,
            role ENUM('superadmin','school_admin','teacher','student') NOT NULL DEFAULT 'student',
            full_name VARCHAR(120) NOT NULL,
            username VARCHAR(50) NOT NULL,
            email VARCHAR(150) NULL,
            password_hash VARCHAR(255) NOT NULL,
            mobile VARCHAR(20) NULL,
            gender ENUM('male','female','other') NULL,
            profile_photo VARCHAR(255) NULL,
            class_id INT UNSIGNED NULL,
            section_id INT UNSIGNED NULL,
            status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
            remember_token VARCHAR(64) NULL,
            remember_expires DATETIME NULL,
            last_login DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_username (username),
            UNIQUE KEY uq_email (email),
            KEY idx_school (school_id),
            KEY idx_role (role),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_schools (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            logo VARCHAR(255) NULL,
            code VARCHAR(20) NOT NULL,
            address TEXT NULL,
            contact_person VARCHAR(120) NULL,
            mobile VARCHAR(20) NULL,
            email VARCHAR(150) NULL,
            plan_id INT UNSIGNED NULL,
            subscription_start DATE NULL,
            subscription_expiry DATE NULL,
            status ENUM('active','suspended','expired') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_code (code),
            KEY idx_status (status),
            KEY idx_plan (plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_subscription_plans (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            type ENUM('per_quiz','per_student','monthly','yearly','custom') NOT NULL DEFAULT 'custom',
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            student_limit INT NULL,
            quiz_limit INT NULL,
            duration_days INT NULL,
            description TEXT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_school_subscriptions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_id INT UNSIGNED NOT NULL,
            plan_id INT UNSIGNED NOT NULL,
            invoice_number VARCHAR(40) NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            start_date DATE NOT NULL,
            expiry_date DATE NULL,
            status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
            payment_method VARCHAR(50) NOT NULL DEFAULT 'manual',
            transaction_ref VARCHAR(100) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_school (school_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_invoices (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(40) NOT NULL,
            school_id INT UNSIGNED NOT NULL,
            subscription_id INT UNSIGNED NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('paid','pending','cancelled') NOT NULL DEFAULT 'pending',
            issued_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            UNIQUE KEY uq_invoice (invoice_number),
            KEY idx_school (school_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_classes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_id INT UNSIGNED NOT NULL,
            name VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_school_class (school_id, name),
            KEY idx_school (school_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_sections (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_id INT UNSIGNED NOT NULL,
            class_id INT UNSIGNED NOT NULL,
            name VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_school_section (school_id, class_id, name),
            KEY idx_school (school_id),
            KEY idx_class (class_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_categories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            icon VARCHAR(30) NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            UNIQUE KEY uq_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_quizzes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_id INT UNSIGNED NOT NULL,
            created_by INT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            category_id INT UNSIGNED NULL,
            instructions TEXT NULL,
            marks_per_question DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            negative_marks DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            pass_percentage DECIMAL(5,2) NOT NULL DEFAULT 40.00,
            duration_minutes INT NOT NULL DEFAULT 10,
            start_datetime DATETIME NULL,
            end_datetime DATETIME NULL,
            max_attempts INT NOT NULL DEFAULT 1,
            entry_fee INT NOT NULL DEFAULT 0,
            completion_bonus INT NOT NULL DEFAULT 0,
            passing_reward INT NOT NULL DEFAULT 0,
            randomize_questions TINYINT(1) NOT NULL DEFAULT 1,
            randomize_options TINYINT(1) NOT NULL DEFAULT 1,
            show_answers TINYINT(1) NOT NULL DEFAULT 0,
            enable_certificate TINYINT(1) NOT NULL DEFAULT 1,
            status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            KEY idx_school (school_id),
            KEY idx_creator (created_by),
            KEY idx_status (status),
            KEY idx_dates (start_datetime, end_datetime),
            KEY idx_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_questions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT UNSIGNED NOT NULL,
            school_id INT UNSIGNED NOT NULL,
            question_text TEXT NOT NULL,
            explanation TEXT NULL,
            marks DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            order_index INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_quiz (quiz_id),
            KEY idx_school (school_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_answer_options (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            question_id INT UNSIGNED NOT NULL,
            option_text TEXT NOT NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            order_index INT NOT NULL DEFAULT 0,
            KEY idx_question (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_quiz_assignments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT UNSIGNED NOT NULL,
            school_id INT UNSIGNED NOT NULL,
            class_id INT UNSIGNED NOT NULL DEFAULT 0,
            section_id INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_assign (quiz_id, class_id, section_id),
            KEY idx_school (school_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_quiz_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            school_id INT UNSIGNED NOT NULL,
            attempt_number INT NOT NULL DEFAULT 1,
            question_order TEXT NULL,
            option_order TEXT NULL,
            status ENUM('in_progress','submitted','expired','abandoned') NOT NULL DEFAULT 'in_progress',
            started_at DATETIME NOT NULL,
            submitted_at DATETIME NULL,
            duration_seconds INT NULL,
            score DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_marks DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            correct_count INT NOT NULL DEFAULT 0,
            wrong_count INT NOT NULL DEFAULT 0,
            unanswered_count INT NOT NULL DEFAULT 0,
            pass_status ENUM('pass','fail','pending') NOT NULL DEFAULT 'pending',
            rank INT NULL,
            points_earned INT NOT NULL DEFAULT 0,
            coins_earned INT NOT NULL DEFAULT 0,
            entry_fee INT NOT NULL DEFAULT 0,
            refunded TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) NULL,
            UNIQUE KEY uq_user_quiz_attempt (user_id, quiz_id, attempt_number),
            KEY idx_quiz (quiz_id),
            KEY idx_school (school_id),
            KEY idx_status (status),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_user_answers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NOT NULL,
            selected_option_id INT UNSIGNED NULL,
            is_correct TINYINT(1) NULL,
            marks_awarded DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            answered_at DATETIME NOT NULL,
            UNIQUE KEY uq_attempt_question (attempt_id, question_id),
            KEY idx_attempt (attempt_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_wallets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            school_id INT UNSIGNED NOT NULL DEFAULT 0,
            balance INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_user (user_id),
            KEY idx_school (school_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_wallet_transactions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(40) NOT NULL,
            transfer_group_id VARCHAR(40) NULL,
            user_id INT UNSIGNED NOT NULL,
            wallet_id INT UNSIGNED NOT NULL,
            school_id INT UNSIGNED NOT NULL DEFAULT 0,
            type ENUM('credit','debit') NOT NULL,
            category ENUM('signup_bonus','admin_add','admin_deduct','entry_fee','refund',
                          'completion_bonus','passing_reward','rank_reward',
                          'transfer_sent','transfer_received','quiz_reward') NOT NULL,
            amount INT NOT NULL,
            balance_after INT NOT NULL,
            sender_user_id INT UNSIGNED NULL,
            sender_username VARCHAR(50) NULL,
            receiver_user_id INT UNSIGNED NULL,
            receiver_username VARCHAR(50) NULL,
            reference VARCHAR(100) NULL,
            description VARCHAR(255) NULL,
            status ENUM('completed','failed','pending','reversed') NOT NULL DEFAULT 'completed',
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_txn (transaction_id),
            KEY idx_user (user_id),
            KEY idx_school (school_id),
            KEY idx_type (type),
            KEY idx_category (category),
            KEY idx_created (created_at),
            KEY idx_group (transfer_group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_quiz_rewards (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT UNSIGNED NOT NULL,
            school_id INT UNSIGNED NOT NULL,
            rank INT NOT NULL,
            coins INT NOT NULL DEFAULT 0,
            points INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_quiz_rank (quiz_id, rank),
            KEY idx_school (school_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_leaderboard_stats (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            school_id INT UNSIGNED NOT NULL DEFAULT 0,
            total_quizzes INT NOT NULL DEFAULT 0,
            total_score DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_points INT NOT NULL DEFAULT 0,
            total_coins_earned INT NOT NULL DEFAULT 0,
            best_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_user (user_id),
            KEY idx_school (school_id),
            KEY idx_points (total_points)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_user_rank_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            rank INT NULL,
            recorded_at DATETIME NOT NULL,
            KEY idx_user (user_id),
            KEY idx_recorded (recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_certificates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            certificate_id VARCHAR(40) NOT NULL,
            attempt_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            quiz_id INT UNSIGNED NOT NULL,
            school_id INT UNSIGNED NOT NULL,
            student_name VARCHAR(120) NOT NULL,
            username VARCHAR(50) NOT NULL,
            score DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            rank INT NULL,
            issue_date DATETIME NOT NULL,
            file_path VARCHAR(255) NULL,
            status ENUM('valid','revoked') NOT NULL DEFAULT 'valid',
            UNIQUE KEY uq_cert (certificate_id),
            UNIQUE KEY uq_attempt (attempt_id),
            KEY idx_user (user_id),
            KEY idx_school (school_id),
            KEY idx_quiz (quiz_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            school_id INT UNSIGNED NULL,
            title VARCHAR(150) NOT NULL,
            message TEXT NULL,
            link VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_user (user_id, is_read),
            KEY idx_school (school_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_settings (
            setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_admin_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(120) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_action (action),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_login_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL,
            KEY idx_lookup (username, ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_token (token_hash),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS qa_contact_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(150) NOT NULL,
            subject VARCHAR(200) NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],

    'seeds' => [
        // Default settings
        "INSERT INTO qa_settings (setting_key, setting_value) VALUES
            ('site_name', 'QuizArena'),
            ('site_tagline', 'The Smart Quiz Platform for Schools'),
            ('site_email', 'support@quizarena.example'),
            ('site_phone', ''),
            ('site_address', ''),
            ('currency_symbol', '₹'),
            ('coin_name', 'Quiz Coins'),
            ('signup_bonus', '50'),
            ('allow_registration', '1'),
            ('leaderboard_public', '1'),
            ('leaderboard_school_wise', '1'),
            ('default_pass_percentage', '40'),
            ('default_entry_fee', '20'),
            ('default_completion_bonus', '5'),
            ('default_passing_reward', '10'),
            ('default_rank1_coins', '100'),
            ('default_rank2_coins', '50'),
            ('default_rank3_coins', '25'),
            ('enable_certificates', '1'),
            ('session_lifetime_hours', '12'),
            ('timezone', 'Asia/Kolkata'),
            ('version', '1.0.0'),
            ('installed', '1')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",

        // Default subscription plans (₹ INR)
        "INSERT INTO qa_subscription_plans (name, type, price, student_limit, quiz_limit, duration_days, description, status, created_at) VALUES
            ('Pay-Per-Quiz', 'per_quiz', 100.00, 100, 10, NULL, '₹100 per student per quiz. Perfect for small assessments.', 'active', NOW()),
            ('Starter Monthly', 'monthly', 1999.00, 100, 20, 30, 'Monthly plan for up to 100 students and 20 quizzes.', 'active', NOW()),
            ('Pro Yearly', 'yearly', 19999.00, 500, 100, 365, 'Yearly plan for up to 500 students and 100 quizzes.', 'active', NOW()),
            ('Custom Plan', 'custom', 0.00, NULL, NULL, NULL, 'Tailored plan for your school. Contact us.', 'active', NOW())",

        // Default quiz categories
        "INSERT INTO qa_categories (name, icon, status) VALUES
            ('Science', 'bi-motherboard', 'active'),
            ('Mathematics', 'bi-calculator', 'active'),
            ('English', 'bi-book', 'active'),
            ('General Knowledge', 'bi-globe2', 'active'),
            ('Computer Science', 'bi-laptop', 'active'),
            ('History', 'bi-clock-history', 'active'),
            ('Geography', 'bi-geo-alt', 'active'),
            ('Sports', 'bi-trophy', 'active'),
            ('Reasoning', 'bi-braces', 'active'),
            ('Current Affairs', 'bi-newspaper', 'active')
        ON DUPLICATE KEY UPDATE name = VALUES(name)",
    ],
];
