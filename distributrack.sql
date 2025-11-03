-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 01, 2025 at 05:08 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `distributrack`
--

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int(10) UNSIGNED NOT NULL,
  `shopkeeper_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cart`
--

INSERT INTO `cart` (`id`, `shopkeeper_id`, `product_id`, `quantity`, `added_at`) VALUES
(1, 3, 7, 1, '2025-10-31 19:32:20'),
(2, 3, 4, 1, '2025-10-31 19:32:24');

-- --------------------------------------------------------

--
-- Table structure for table `distributor`
--

CREATE TABLE `distributor` (
  `id` int(10) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `business_license` varchar(100) NOT NULL,
  `nid` varchar(30) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `distributor`
--

INSERT INTO `distributor` (`id`, `first_name`, `last_name`, `email`, `password`, `company_name`, `business_license`, `nid`, `phone`, `address`, `created_at`) VALUES
(1, 'Kayes', 'Mahmood', 'kayesmahmood2013@gmail.com', '$2y$10$VXFnnNamsRwqlqiPFBNz7O66TJsP7V93Gu80/2PXMcZ/v1ba9X.iy', 'UITS', '1234567', '123456789', '0182832312', 'Mohakhali, Dhaka-1212', '2025-05-27 02:59:18'),
(3, 'Noor', 'A Alam', 'noor@gmail.com', '$2y$10$JJ3gTiLry4rl1m5.ubZhr.O/gUQSoUU3cAe7TM1oJatts2hLukRCW', 'UITS', '123', '123456789', '014785234452', 'j block', '2025-05-27 04:40:32'),
(4, 'Israt', 'Sultana', 'israt@gmail.com', '$2y$10$NFmzMRHPi1SaS5W3uRYYv.y8NqSHZIGbf8Ch7twtiB6epghRbVZJa', 'Kichuna', '123456', '123456', '01843966799', 'Badda', '2025-10-21 13:19:35'),
(5, 'Kayes', 'Mahmood', 'kayesmahmood20@gmail.com', '$2y$10$UOrchleTvGQ1LUn4/PSzbeVvS/62AmceSp.Nmb/KaezutRo1.r/Lq', 'Nai', '12345669', '12345669', '+8801832832312', 'Mohakhali, Dhaka-1212', '2025-10-31 19:30:39'),
(6, 'Kayes', 'Mahmood', 'kayesmahmood210@gmail.com', '$2y$10$7uwUSLsVdjXaTJjPYCPnXOG.29/GRhp8AWlCilr/ER.gB1mRsgF4m', 'Nai', '12345669', '12345669', '+8801832832311', 'Mohakhali, Dhaka-1212', '2025-11-01 16:03:54');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(10) UNSIGNED NOT NULL,
  `distributor_id` int(10) UNSIGNED NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `distributor_id`, `product_name`, `description`, `price`, `quantity`, `image_path`, `created_at`, `is_active`, `updated_at`) VALUES
(1, 1, 'MOJO', '', 1800.00, 100, 'uploads/1748315879__0024_3.jpg', '2025-05-27 03:17:59', 1, NULL),
(2, 1, 'MOJO', '', 200.00, 10, 'uploads/1748320957__0024_3.jpg', '2025-05-27 04:42:37', 1, NULL),
(3, 1, 'MOJO', '', 200.00, 10, 'uploads/1748323386__0024_3.jpg', '2025-05-27 05:23:06', 1, NULL),
(4, 1, 'mojo', '', 200.00, 10, 'uploads/1748323519__0024_3.jpg', '2025-05-27 05:25:19', 1, NULL),
(6, 4, 'dancake', '', 30.00, 30, 'uploads/1761053814_Chocolate-Slice-Cake18.jpg', '2025-10-21 13:36:54', 1, NULL),
(7, 5, 'mojo', '', 200.00, 10, 'uploads/9f8c3f480a740f7f_1761939079.jpg', '2025-10-31 19:31:19', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_type` enum('Shopkeeper','Distributor') NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_type`, `user_id`, `type`, `payload`, `is_read`, `created_at`) VALUES
(1, 'Shopkeeper', 1, 'ORDER_STATUS_CHANGED', '{\"order_id\":123,\"old\":\"Pending\",\"new\":\"Processing\"}', 0, '2025-10-31 15:24:27');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(10) UNSIGNED NOT NULL,
  `shopkeeper_id` int(10) UNSIGNED NOT NULL,
  `distributor_id` int(10) UNSIGNED NOT NULL,
  `total_amount` decimal(10,2) UNSIGNED NOT NULL,
  `order_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Processing','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `shopkeeper_id`, `distributor_id`, `total_amount`, `order_date`, `status`, `updated_at`) VALUES
(2, 1, 1, 0.00, '2025-10-31 15:19:05', 'Pending', NULL),
(3, 1, 1, 0.00, '2025-10-31 15:21:39', 'Pending', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `price` decimal(10,2) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `order_items`
--
DELIMITER $$
CREATE TRIGGER `trg_order_items_ad` AFTER DELETE ON `order_items` FOR EACH ROW BEGIN
  UPDATE orders o
  JOIN (
    SELECT order_id, SUM(quantity * price) AS sum_total
    FROM order_items WHERE order_id = OLD.order_id
  ) s ON s.order_id = o.id
  SET o.total_amount = IFNULL(s.sum_total, 0.00);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_order_items_aiu` AFTER INSERT ON `order_items` FOR EACH ROW BEGIN
  UPDATE orders o
  JOIN (
    SELECT order_id, SUM(quantity * price) AS sum_total
    FROM order_items WHERE order_id = NEW.order_id
  ) s ON s.order_id = o.id
  SET o.total_amount = s.sum_total;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_order_items_au` AFTER UPDATE ON `order_items` FOR EACH ROW BEGIN
  UPDATE orders o
  JOIN (
    SELECT order_id, SUM(quantity * price) AS sum_total
    FROM order_items WHERE order_id = NEW.order_id
  ) s ON s.order_id = o.id
  SET o.total_amount = s.sum_total;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `provider` varchar(50) NOT NULL,
  `provider_txn_id` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) UNSIGNED NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'BDT',
  `status` enum('Initiated','Succeeded','Failed','Refunded') NOT NULL DEFAULT 'Initiated',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_reports`
--

CREATE TABLE `sales_reports` (
  `id` int(10) UNSIGNED NOT NULL,
  `shopkeeper_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `quantity_sold` int(10) UNSIGNED NOT NULL,
  `total_price` decimal(10,2) UNSIGNED NOT NULL,
  `sold_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shopkeeper`
--

CREATE TABLE `shopkeeper` (
  `id` int(10) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nid` varchar(30) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `shop_name` varchar(100) NOT NULL,
  `shop_address` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shopkeeper`
--

INSERT INTO `shopkeeper` (`id`, `first_name`, `last_name`, `email`, `password`, `nid`, `phone`, `shop_name`, `shop_address`, `created_at`) VALUES
(1, 'Kayes', 'Mahmood', 'kayesmahmood@gmail.com', '$2y$10$Rtw0HzyTBLjl0Xo0Ns7waeIhUk.C7hJ838xXnfYgbKyclucawk.kK', '558822', '01832832311', 'UITS', 'Mohakhali, Dhaka-1212', '2025-05-27 03:19:07'),
(2, 'Esteak', 'Tarahi', 'tarahi@gmail.com', '$2y$10$/V//i5ZSF1a1QB/N3btGWuX0dSptKQ9dVdU8rTWEO54guky/wuEcS', '123456', '01728922922', 'Tarahi Store', 'Badda', '2025-10-21 13:22:42'),
(3, 'Minhazur', 'Rahman', 'minhazrahman24uits@gmail.com', '$2y$10$mDx582s5VJl4WQ94xa9x1uDEqoO.qBVRgP3cI/HrMsy3/xG1Y5QUe', '265486331', '+8801892415157', 'UITS', 'Mohakhali, Dhaka-1212', '2025-10-31 19:32:12');

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `id` int(10) UNSIGNED NOT NULL,
  `inventory_id` int(10) UNSIGNED NOT NULL,
  `change_qty` int(11) NOT NULL,
  `reason` enum('Manual','OrderPlaced','OrderCancelled','Adjustment') NOT NULL,
  `ref_type` enum('order','admin','system') DEFAULT 'system',
  `ref_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_movements`
--

INSERT INTO `stock_movements` (`id`, `inventory_id`, `change_qty`, `reason`, `ref_type`, `ref_id`, `created_at`) VALUES
(1, 1, -2, 'OrderPlaced', 'order', NULL, '2025-10-31 15:24:50');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shopkeeper_id` (`shopkeeper_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_cart_shopkeeper_date` (`shopkeeper_id`,`added_at`);

--
-- Indexes for table `distributor`
--
ALTER TABLE `distributor`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_distributor_company` (`company_name`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `distributor_id` (`distributor_id`),
  ADD KEY `idx_inventory_distributor` (`distributor_id`),
  ADD KEY `idx_inventory_active` (`is_active`),
  ADD KEY `idx_inventory_search` (`product_name`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user` (`user_type`,`user_id`,`is_read`,`created_at`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shopkeeper_id` (`shopkeeper_id`),
  ADD KEY `distributor_id` (`distributor_id`),
  ADD KEY `idx_orders_shopkeeper_status_date` (`shopkeeper_id`,`status`,`order_date`),
  ADD KEY `idx_orders_distributor_status_date` (`distributor_id`,`status`,`order_date`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_order_item` (`order_id`,`product_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payments_provider_txn` (`provider`,`provider_txn_id`),
  ADD KEY `idx_payments_order_status` (`order_id`,`status`);

--
-- Indexes for table `sales_reports`
--
ALTER TABLE `sales_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shopkeeper_id` (`shopkeeper_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_sales_reports_shopkeeper_date` (`shopkeeper_id`,`sold_at`);

--
-- Indexes for table `shopkeeper`
--
ALTER TABLE `shopkeeper`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_shopkeeper_phone` (`phone`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stock_mov_inventory_time` (`inventory_id`,`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `distributor`
--
ALTER TABLE `distributor`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_reports`
--
ALTER TABLE `sales_reports`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shopkeeper`
--
ALTER TABLE `shopkeeper`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`shopkeeper_id`) REFERENCES `shopkeeper` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`distributor_id`) REFERENCES `distributor` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`shopkeeper_id`) REFERENCES `shopkeeper` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`distributor_id`) REFERENCES `distributor` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_reports`
--
ALTER TABLE `sales_reports`
  ADD CONSTRAINT `sales_reports_ibfk_1` FOREIGN KEY (`shopkeeper_id`) REFERENCES `shopkeeper` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_reports_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `fk_stock_mov_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
