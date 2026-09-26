-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 26, 2026 at 05:24 PM
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
-- Database: `food_ordering_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`category_id`, `category_name`, `description`, `created_at`) VALUES
(1, 'Main Course', 'Hearty meals and entrees', '2026-09-21 06:42:55'),
(2, 'Beverages', 'Refreshing cold drinks and hot beverages', '2026-09-21 06:42:55'),
(3, 'Beverages', NULL, '2026-09-21 07:08:33');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) NOT NULL,
  `order_type` enum('Dine-In','Takeout','Delivery') DEFAULT 'Dine-In',
  `payment_method` enum('Cash','GCash','Credit/Debit Card') DEFAULT 'Cash',
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `order_status` enum('Pending','Processing','Completed','Cancelled') DEFAULT 'Pending',
  `order_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`order_id`, `customer_name`, `customer_email`, `phone_number`, `order_type`, `payment_method`, `total_amount`, `order_status`, `order_date`) VALUES
(4, 'John Patrick Salmazan Arqueza (Address: Blk 1 Brgy Ungot&#13;&#10;Guava street)', 'patweak8826@gmail.com', '09094044638', 'Delivery', 'Cash', 180.00, 'Pending', '2026-09-21 07:35:37'),
(5, 'John Michael Mendoza (Address: Matapa Street Block 16 Lot 05 Brgy. San Pablo Tarlac City)', 'jmmendoza081@gmail.com', '09092911170', 'Delivery', 'Cash', 105.00, 'Pending', '2026-09-21 07:59:38'),
(6, 'jm (Address: Matapa Street Block 16 Lot 05 Brgy. San Pablo Tarlac City)', 'jmmendoza081@gmail.com', '09092911170', 'Delivery', 'Cash', 60.00, 'Completed', '2026-09-26 15:08:32');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `item_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `customization_note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`item_id`, `order_id`, `product_id`, `quantity`, `unit_price`, `customization_note`) VALUES
(4, 4, 7, 1, 85.00, 'make it toasted'),
(5, 4, 3, 1, 95.00, 'less ice'),
(6, 5, 10, 1, 60.00, 'less ice'),
(7, 5, 9, 1, 45.00, 'no ice'),
(8, 6, 10, 1, 60.00, '');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `status` enum('Available','Out of Stock') DEFAULT 'Available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `category_id`, `product_name`, `price`, `stock_quantity`, `status`, `created_at`) VALUES
(1, 1, 'Classic Beef Burger', 180.00, 24, 'Available', '2026-09-21 06:42:55'),
(2, 1, 'Sizzling Chicken Sisig', 220.00, 15, 'Available', '2026-09-21 06:42:55'),
(3, 2, 'Iced Milk Tea (Large)', 95.00, 48, 'Available', '2026-09-21 06:42:55'),
(4, 1, 'Special Bulalo', 280.00, 20, 'Available', '2026-09-21 07:08:33'),
(5, 1, 'Classic Papaitan', 180.00, 20, 'Available', '2026-09-21 07:08:33'),
(6, 2, 'Pepperoni Pizza', 250.00, 15, 'Available', '2026-09-21 07:08:33'),
(7, 2, 'Crispy French Fries', 85.00, 49, 'Available', '2026-09-21 07:08:33'),
(8, 3, 'Iced Frappe', 120.00, 30, 'Available', '2026-09-21 07:08:33'),
(9, 3, 'Ice Cold Softdrinks', 45.00, 99, 'Available', '2026-09-21 07:08:33'),
(10, 3, 'Fresh Fruit Juice', 60.00, 37, 'Available', '2026-09-21 07:08:33');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD KEY `category_id` (`category_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
