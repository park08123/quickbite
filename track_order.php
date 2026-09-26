<?php
require_once 'database.php';
require_once 'order.php';

$db = (new Database())->getConnection();
$errors = [];
$trackedOrderItems = [];
$searchOrderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);

if ($searchOrderId && $searchOrderId > 0) {
    $trackStmt = $db->prepare("
        SELECT o.*, oi.quantity, oi.unit_price, oi.customization_note, p.product_name 
        FROM orders o
        JOIN order_items oi ON o.order_id = oi.order_id
        JOIN products p ON oi.product_id = p.product_id
        WHERE o.order_id = ?
    ");
    $trackStmt->execute([$searchOrderId]);
    $trackedOrderItems = $trackStmt->fetchAll();

    if (empty($trackedOrderItems)) {
        $errors[] = "No order record found for Order ID #{$searchOrderId}.";
    }
} elseif (isset($_GET['order_id'])) {
    $errors[] = "Please enter a valid numeric Order ID.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Order Status - QuickBite</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="main-header">
    <h1>QuickBite Express Delivery</h1>
    <p>Track your order status and details in real-time.</p>
</div>

<div style="max-width: 600px; margin: 0 auto;">
    <div class="card">
        <h2>Track Order Status</h2>

        <?php if (!empty($_GET['success']) && $searchOrderId): ?>
            <div class="alert-success">
                Delivery Order placed successfully! Your Order Reference ID is <strong>#<?= $searchOrderId ?></strong>.
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert-error">
                <?php foreach ($errors as $err): ?>
                    <p style="margin: 2px 0;"><?= htmlspecialchars($err) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="GET" action="track_order.php">
            <div class="form-group">
                <label>Order Reference ID #</label>
                <input type="number" name="order_id" placeholder="e.g. 1" value="<?= htmlspecialchars($searchOrderId ?: '') ?>" required>
            </div>
            <button type="submit" class="btn-track">Check Status</button>
        </form>

        <?php if (!empty($trackedOrderItems)): ?>
            <?php $firstRow = $trackedOrderItems[0]; ?>
            <div style="border-top: 2px dashed #ddd; margin-top: 15px; padding-top: 10px; font-size: 0.9em;">
                <p><strong>Order ID:</strong> #<?= $firstRow['order_id'] ?></p>
                <p><strong>Customer:</strong> <?= htmlspecialchars($firstRow['customer_name']) ?></p>
                <p><strong>Phone:</strong> <?= htmlspecialchars($firstRow['phone_number']) ?></p>
                <?php if (!empty($firstRow['customer_email'])): ?>
                    <p><strong>Email:</strong> <?= htmlspecialchars($firstRow['customer_email']) ?></p>
                <?php endif; ?>
                <p><strong>Payment Method:</strong> <?= htmlspecialchars($firstRow['payment_method']) ?></p>
                <p><strong>Items Ordered:</strong></p>
                <ul>
                    <?php foreach ($trackedOrderItems as $tItem): ?>
                        <li>
                            <strong><?= htmlspecialchars($tItem['product_name']) ?></strong> x <?= $tItem['quantity'] ?> (₱<?= number_format($tItem['unit_price'] * $tItem['quantity'], 2) ?>)
                            <?php if (!empty($tItem['customization_note'])): ?>
                                <br><small style="color: #666; font-style: italic;">Note: "<?= htmlspecialchars($tItem['customization_note']) ?>"</small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p><strong>Total Price:</strong> ₱<?= number_format($firstRow['total_amount'], 2) ?></p>
                <p>
                    <strong>Status:</strong> 
                    <span class="status-badge status-<?= $firstRow['order_status'] ?>">
                        <?= $firstRow['order_status'] ?>
                    </span>
                </p>
            </div>
        <?php endif; ?>

        <div style="margin-top: 15px; text-align: center;">
            <a href="order_form.php" style="color: var(--primary-orange); text-decoration: none; font-weight: bold;">← Place Another Order</a>
        </div>
    </div>
</div>

</body>
</html>