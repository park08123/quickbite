<?php
require_once 'database.php';
require_once 'product.php';

$db = (new Database())->getConnection();

$actionMessage = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    try {
        if ($action === 'update_order_status' && $orderId > 0) {
            $allowedStatuses = ['Pending', 'Processing', 'Completed', 'Cancelled'];
            $newStatus = $_POST['order_status'] ?? '';
            if (!in_array($newStatus, $allowedStatuses, true)) {
                throw new Exception('Invalid order status selected.');
            }

            $stmt = $db->prepare('UPDATE orders SET order_status = :status WHERE order_id = :order_id');
            $stmt->execute([':status' => $newStatus, ':order_id' => $orderId]);
            $actionMessage = "Order #{$orderId} status updated.";
        } elseif ($action === 'delete_order' && $orderId > 0) {
            $db->beginTransaction();

            $itemStmt = $db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
            $itemStmt->execute([$orderId]);
            $itemsToRestore = $itemStmt->fetchAll();

            foreach ($itemsToRestore as $item) {
                $restoreStmt = $db->prepare('UPDATE products SET stock_quantity = stock_quantity + :quantity WHERE product_id = :product_id');
                $restoreStmt->execute([
                    ':quantity' => $item['quantity'],
                    ':product_id' => $item['product_id']
                ]);
            }

            $deleteItems = $db->prepare('DELETE FROM order_items WHERE order_id = ?');
            $deleteItems->execute([$orderId]);
            $deleteOrder = $db->prepare('DELETE FROM orders WHERE order_id = ?');
            $deleteOrder->execute([$orderId]);
            $db->commit();
            $actionMessage = "Order #{$orderId} deleted and item stock restored.";
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $actionError = $e->getMessage();
    }
}

// Query Metrics for Dashboard Summary View
$totalOrders = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$pendingOrders = $db->query("SELECT COUNT(*) FROM orders WHERE order_status = 'Pending'")->fetchColumn();
$totalRevenue = $db->query("SELECT IFNULL(SUM(total_amount), 0) FROM orders WHERE order_status != 'Cancelled'")->fetchColumn();
$lowStock = $db->query("SELECT COUNT(*) FROM products WHERE stock_quantity < 5")->fetchColumn();

// Table Filtering & Sorting Parameters
$search = $_GET['search'] ?? '';
$categoryId = (int)($_GET['category_id'] ?? 0);
$sortBy = $_GET['sort_by'] ?? 'product_id';
$order = $_GET['order'] ?? 'DESC';

$productList = Product::fetchFiltered($db, $search, $categoryId, $sortBy, $order, 10);
$categories = $db->query("SELECT * FROM categories")->fetchAll();
$orderList = $db->query("SELECT o.*, COALESCE(GROUP_CONCAT(CONCAT(p.product_name, ' x', oi.quantity) SEPARATOR ', '), 'No items') AS item_summary FROM orders o LEFT JOIN order_items oi ON o.order_id = oi.order_id LEFT JOIN products p ON oi.product_id = p.product_id GROUP BY o.order_id ORDER BY o.order_id DESC")->fetchAll();
$hourlyOrders = $db->query("SELECT HOUR(order_date) AS order_hour, COUNT(*) AS order_count FROM orders GROUP BY HOUR(order_date) ORDER BY order_hour")->fetchAll();
$topProducts = $db->query("SELECT p.product_name, SUM(oi.quantity) AS units_sold, SUM(oi.quantity * oi.unit_price) AS sales_total FROM order_items oi JOIN products p ON oi.product_id = p.product_id JOIN orders o ON oi.order_id = o.order_id WHERE o.order_status != 'Cancelled' GROUP BY p.product_id, p.product_name ORDER BY units_sold DESC LIMIT 5")->fetchAll();
$statusDistribution = $db->query("SELECT order_status, COUNT(*) AS status_count FROM orders GROUP BY order_status ORDER BY status_count DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard & Inventory - Online Ordering</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f8f9fa; }
        .metrics-grid { display: flex; gap: 20px; margin-bottom: 25px; }
        .metric-card { flex: 1; background: #fff; padding: 15px; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); border-left: 4px solid #007bff; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 15px; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #e9ecef; }
        .filter-bar { display: flex; gap: 10px; background: #fff; padding: 15px; border-radius: 6px; }
        .dashboard-message { padding: 12px 15px; margin: 12px 0; border-radius: 6px; font-weight: bold; }
        .dashboard-message.success { background: #dff6eb; color: #16744f; }
        .dashboard-message.error { background: #fde4e4; color: #9f2929; }
        .records-section { margin-top: 32px; }
        .section-title-row { display: flex; justify-content: space-between; align-items: center; gap: 20px; }
        .section-title-row p { color: #687381; margin-top: -8px; }
        .action-link { background: #135ae7; color: #fff; padding: 10px 14px; border-radius: 5px; text-decoration: none; white-space: nowrap; }
        .inline-form { display: inline; }
        .inline-form select { padding: 5px; }
        .record-actions { white-space: nowrap; }
        .record-actions a, .delete-link { margin-right: 8px; color: #135ae7; background: none; border: 0; padding: 0; text-decoration: underline; cursor: pointer; font: inherit; }
        .record-actions .delete-link { color: #d43b52; }
        .analytics-section { margin-top: 32px; }
        .analytics-updated { color: #198754; font-size: 13px; }
        .analytics-grid { display: grid; grid-template-columns: 1.3fr 1fr 1fr; gap: 16px; }
        .analytics-card { background: #fff; padding: 18px; border-radius: 7px; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
        .analytics-card h3 { margin-top: 0; color: #27364a; font-size: 16px; }
        .bar-row, .ranking-row, .status-row { display: grid; align-items: center; gap: 8px; margin: 12px 0; font-size: 13px; }
        .bar-row { grid-template-columns: 42px 1fr 20px; }
        .bar-track { height: 10px; overflow: hidden; border-radius: 5px; background: #edf0f7; }
        .bar-track i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #135ae7, #e12ade); }
        .ranking-row { grid-template-columns: 20px 1fr auto; }
        .ranking-row b { color: #e12ade; }
        .ranking-row strong { color: #198754; font-size: 12px; }
        .status-row { grid-template-columns: 1fr auto; padding: 8px 10px; border-radius: 4px; background: #f5f7fb; }
        .status-row strong { color: #135ae7; }
        @media (max-width: 900px) { .analytics-grid { grid-template-columns: 1fr; } }
        @media (max-width: 700px) { .section-title-row { align-items: flex-start; flex-direction: column; } table { display: block; overflow-x: auto; } }
    </style>
</head>
<body>

<h1>System Dashboard</h1>

<?php if ($actionMessage): ?><div class="dashboard-message success"><?= htmlspecialchars($actionMessage) ?></div><?php endif; ?>
<?php if ($actionError): ?><div class="dashboard-message error"><?= htmlspecialchars($actionError) ?></div><?php endif; ?>

<div class="metrics-grid">
    <div class="metric-card"><h3>Total Orders</h3><p><?= $totalOrders ?></p></div>
    <div class="metric-card"><h3>Pending Orders</h3><p><?= $pendingOrders ?></p></div>
    <div class="metric-card"><h3>Total Revenue</h3><p>PHP <?= number_format($totalRevenue, 2) ?></p></div>
    <div class="metric-card" style="border-left-color: #dc3545;"><h3>Low Stock Items</h3><p><?= $lowStock ?></p></div>
</div>

<h2>Product Catalog & Inventory View</h2>

<form class="filter-bar" method="GET" action="">
    <input type="text" name="search" placeholder="Search product..." value="<?= htmlspecialchars($search) ?>">
    
    <select name="category_id">
        <option value="0">All Categories</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['category_id'] ?>" <?= $categoryId == $cat['category_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['category_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="sort_by">
        <option value="product_id" <?= $sortBy == 'product_id' ? 'selected' : '' ?>>Sort by ID</option>
        <option value="product_name" <?= $sortBy == 'product_name' ? 'selected' : '' ?>>Sort by Name</option>
        <option value="price" <?= $sortBy == 'price' ? 'selected' : '' ?>>Sort by Price</option>
        <option value="stock_quantity" <?= $sortBy == 'stock_quantity' ? 'selected' : '' ?>>Sort by Stock</option>
    </select>

    <select name="order">
        <option value="DESC" <?= $order == 'DESC' ? 'selected' : '' ?>>Descending</option>
        <option value="ASC" <?= $order == 'ASC' ? 'selected' : '' ?>>Ascending</option>
    </select>

    <button type="submit">Filter & Sort</button>
</form>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Product Name</th>
            <th>Category</th>
            <th>Price</th>
            <th>Stock</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($productList)): ?>
            <?php foreach ($productList as $p): ?>
                <tr>
                    <td><?= $p['product_id'] ?></td>
                    <td><?= htmlspecialchars($p['product_name']) ?></td>
                    <td><?= htmlspecialchars($p['category_name']) ?></td>
                    <td>PHP <?= number_format($p['price'], 2) ?></td>
                    <td><?= $p['stock_quantity'] ?></td>
                    <td><?= $p['stock_quantity'] > 0 ? 'Available' : 'Out of Stock' ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="6">No products found.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<section class="records-section">
    <div class="section-title-row">
        <div>
            <h2>Order Records Management</h2>
            <p>Create orders from the delivery form, then view, update, or delete them here.</p>
        </div>
        <a class="action-link" href="order_form.php">+ New Order</a>
    </div>

    <table>
        <thead>
            <tr>
                <th>Order #</th>
                <th>Customer / Phone</th>
                <th>Items Ordered</th>
                <th>Total</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($orderList)): ?>
                <?php foreach ($orderList as $orderRow): ?>
                    <tr>
                        <td>#<?= (int)$orderRow['order_id'] ?></td>
                        <td><?= htmlspecialchars($orderRow['customer_name']) ?><br><small><?= htmlspecialchars($orderRow['phone_number']) ?></small></td>
                        <td><?= htmlspecialchars($orderRow['item_summary']) ?></td>
                        <td>₱<?= number_format($orderRow['total_amount'], 2) ?></td>
                        <td>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="update_order_status">
                                <input type="hidden" name="order_id" value="<?= (int)$orderRow['order_id'] ?>">
                                <select name="order_status" onchange="this.form.submit()">
                                    <?php foreach (['Pending', 'Processing', 'Completed', 'Cancelled'] as $status): ?>
                                        <option value="<?= $status ?>" <?= $orderRow['order_status'] === $status ? 'selected' : '' ?>><?= $status ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td class="record-actions">
                            <a href="track_order.php?order_id=<?= (int)$orderRow['order_id'] ?>">View</a>
                            <form method="POST" class="inline-form" onsubmit="return confirm('Delete this order and restore its stock?');">
                                <input type="hidden" name="action" value="delete_order">
                                <input type="hidden" name="order_id" value="<?= (int)$orderRow['order_id'] ?>">
                                <button type="submit" class="delete-link">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6">No orders found. Create one from the delivery form.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<section class="analytics-section">
    <div class="section-title-row">
        <div>
            <h2>Delivery Analytics</h2>
            <p>Order volume, best-selling dishes, and current status distribution.</p>
        </div>
        <span class="analytics-updated">Live database summary</span>
    </div>
    <div class="analytics-grid">
        <div class="analytics-card">
            <h3>Hourly Order Volume</h3>
            <?php if ($hourlyOrders): ?>
                <?php $maxHourlyOrders = max(array_column($hourlyOrders, 'order_count')); ?>
                <?php foreach ($hourlyOrders as $hour): ?>
                    <div class="bar-row"><span><?= sprintf('%02d:00', $hour['order_hour']) ?></span><div class="bar-track"><i style="width: <?= ($hour['order_count'] / $maxHourlyOrders) * 100 ?>%"></i></div><b><?= $hour['order_count'] ?></b></div>
                <?php endforeach; ?>
            <?php else: ?><p>No order activity yet.</p><?php endif; ?>
        </div>
        <div class="analytics-card">
            <h3>Top Dishes</h3>
            <?php if ($topProducts): ?>
                <?php foreach ($topProducts as $index => $product): ?>
                    <div class="ranking-row"><b><?= $index + 1 ?></b><span><?= htmlspecialchars($product['product_name']) ?></span><strong><?= (int)$product['units_sold'] ?> sold</strong></div>
                <?php endforeach; ?>
            <?php else: ?><p>No sales data yet.</p><?php endif; ?>
        </div>
        <div class="analytics-card">
            <h3>Status Distribution</h3>
            <?php if ($statusDistribution): ?>
                <?php foreach ($statusDistribution as $status): ?>
                    <div class="status-row"><span><?= htmlspecialchars($status['order_status']) ?></span><strong><?= (int)$status['status_count'] ?></strong></div>
                <?php endforeach; ?>
            <?php else: ?><p>No status data yet.</p><?php endif; ?>
        </div>
    </div>
</section>

</body>
</html>