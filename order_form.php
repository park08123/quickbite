<?php
require_once 'database.php';
require_once 'order.php';

$db = (new Database())->getConnection();
$errors = [];

$orderMetrics = [
    'total' => (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
    'pending' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE order_status = 'Pending'")->fetchColumn(),
    'out_for_delivery' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('Processing', 'Out for Delivery')")->fetchColumn(),
    'completed' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('Completed', 'Delivered')")->fetchColumn(),
    'revenue' => (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE order_status != 'Cancelled'")->fetchColumn()
];

// Handle Order Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    $customerName   = trim(filter_input(INPUT_POST, 'customer_name', FILTER_SANITIZE_SPECIAL_CHARS));
    $phoneNumber    = trim(filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_SPECIAL_CHARS));
    $customerEmail  = trim(filter_input(INPUT_POST, 'customer_email', FILTER_VALIDATE_EMAIL));
    $deliveryAddress = trim(filter_input(INPUT_POST, 'delivery_address', FILTER_SANITIZE_SPECIAL_CHARS));
    $paymentMethod  = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_SPECIAL_CHARS);
    $selectedItems  = $_POST['items'] ?? []; 
    $itemNotes      = $_POST['notes'] ?? []; 

    // Validation
    if (!$customerName) { $errors[] = "Full Name is required."; }
    if (!$phoneNumber) { $errors[] = "Phone Number is required for delivery processing."; }
    if (!$deliveryAddress) { $errors[] = "Delivery Address is required for system delivery."; }
    if (!in_array($paymentMethod, ['Cash', 'GCash'])) { $errors[] = "Invalid Payment Method selected."; }
    
    $orderItemsToProcess = [];
    if (is_array($selectedItems)) {
        foreach ($selectedItems as $pId => $qty) {
            $pIdInt = (int)$pId;
            $qtyInt = (int)$qty;
            if ($pIdInt > 0 && $qtyInt > 0) {
                $orderItemsToProcess[$pIdInt] = [
                    'qty'  => $qtyInt,
                    'note' => trim(htmlspecialchars($itemNotes[$pIdInt] ?? ''))
                ];
            }
        }
    }

    if (empty($orderItemsToProcess)) {
        $errors[] = "Please select at least one menu item with a quantity of 1 or more.";
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $totalAmount = 0.00;
            $validatedItems = [];

            foreach ($orderItemsToProcess as $pId => $itemData) {
                $qty = $itemData['qty'];
                $pStmt = $db->prepare("SELECT product_name, price, stock_quantity FROM products WHERE product_id = ?");
                $pStmt->execute([$pId]);
                $product = $pStmt->fetch();

                if (!$product) {
                    throw new Exception("Product ID #{$pId} does not exist.");
                }
                if ($product['stock_quantity'] < $qty) {
                    throw new Exception("Insufficient stock for '{$product['product_name']}'. Only {$product['stock_quantity']} available.");
                }

                $totalAmount += $product['price'] * $qty;
                $validatedItems[] = [
                    'product_id' => $pId,
                    'price'      => $product['price'],
                    'quantity'   => $qty,
                    'note'       => $itemData['note']
                ];
            }

            $stmt = $db->prepare("INSERT INTO orders (customer_name, phone_number, customer_email, order_type, payment_method, total_amount, order_status) VALUES (:name, :phone, :email, 'Delivery', :payment, :total, 'Pending')");
            $stmt->execute([
                ':name'    => $customerName . " (Address: " . $deliveryAddress . ")",
                ':phone'   => $phoneNumber,
                ':email'   => $customerEmail ?: null,
                ':payment' => $paymentMethod,
                ':total'   => $totalAmount
            ]);
            $orderId = (int)$db->lastInsertId();

            $stmtItem  = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_note) VALUES (:order_id, :product_id, :qty, :price, :note)");
            $stmtStock = $db->prepare("UPDATE products SET stock_quantity = stock_quantity - :qty WHERE product_id = :product_id");

            foreach ($validatedItems as $vItem) {
                $stmtItem->execute([
                    ':order_id'   => $orderId,
                    ':product_id' => $vItem['product_id'],
                    ':qty'        => $vItem['quantity'],
                    ':price'      => $vItem['price'],
                    ':note'       => $vItem['note']
                ]);

                $stmtStock->execute([
                    ':qty'        => $vItem['quantity'],
                    ':product_id' => $vItem['product_id']
                ]);
            }

            $db->commit();

            // Redirect directly to the tracking page for this order
            header("Location: track_order.php?order_id={$orderId}&success=1");
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            $errors[] = "Order Failed: " . $e->getMessage();
        }
    }
}

// Fetch menu items
$menuItems = $db->query("SELECT p.*, c.category_name FROM products p JOIN categories c ON p.category_id = c.category_id WHERE p.stock_quantity > 0 ORDER BY c.category_name, p.product_name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickBite Express Delivery</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="main-header">
    <div class="eyebrow">EXPRESS POS & DELIVERY DISPATCH</div>
    <h1>QuickBite Express Delivery</h1>
    <p>Select your favorite dishes, customize your order notes, and adjust quantities!</p>
</div>

<div class="metrics-grid">
    <div class="metric-card metric-blue"><span>Total Orders</span><strong><?= $orderMetrics['total'] ?></strong><small>+12% today</small></div>
    <div class="metric-card metric-yellow"><span>Pending / In Queue</span><strong><?= $orderMetrics['pending'] ?></strong><small>Awaiting dispatch</small></div>
    <div class="metric-card metric-cyan"><span>Out for Delivery</span><strong><?= $orderMetrics['out_for_delivery'] ?></strong><small>Active dispatch</small></div>
    <div class="metric-card metric-green"><span>Completed</span><strong><?= $orderMetrics['completed'] ?></strong><small>Successfully delivered</small></div>
    <div class="metric-card metric-pink"><span>Total Revenue</span><strong>₱<?= number_format($orderMetrics['revenue'], 2) ?></strong><small>Gross today</small></div>
</div>

<div class="catalog-toolbar">
    <input type="search" id="menuSearch" placeholder="Search menu items..." aria-label="Search menu items">
    <div class="category-tabs" role="tablist">
        <button type="button" class="active" data-category="all">All Dishes</button>
        <button type="button" data-category="beverages">Beverages</button>
        <button type="button" data-category="meals">Meals</button>
        <button type="button" data-category="snacks">Snacks &amp; Sides</button>
    </div>
    <label class="sort-control">Sort by <select id="menuSort"><option value="name">Featured Order</option><option value="price">Price</option></select></label>
</div>

<div class="layout-grid">

    <!-- MENU CATALOG BOOKLET -->
    <div class="menu-booklet">
        <div class="menu-header-bar">★ SYSTEM DELIVERY MENU ★</div>

        <div class="menu-grid">
            <?php foreach ($menuItems as $item): ?>
                <?php 
                    $name = strtolower($item['product_name']);
                    $imgUrl = "https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=150";
                    $menuCategory = strtolower($item['category_name']);

                    if (strpos($name, 'papaitan') !== false || strpos($name, 'sisig') !== false || strpos($name, 'bulalo') !== false) {
                        $menuCategory = 'meals';
                    } elseif (strpos($name, 'pizza') !== false || strpos($name, 'fries') !== false) {
                        $menuCategory = 'snacks';
                    }

                    if (strpos($name, 'pizza') !== false) {
                        $imgUrl = "https://images.unsplash.com/photo-1513104890138-7c749659a591?w=150";
                    } elseif (strpos($name, 'fries') !== false) {
                        $imgUrl = "https://images.unsplash.com/photo-1630384060421-cb20d0e0649d?w=150";
                    } elseif (strpos($name, 'bulalo') !== false || strpos($name, 'papaitan') !== false || strpos($name, 'soup') !== false) {
                        $imgUrl = "https://images.unsplash.com/photo-1547592180-85f173990554?w=150";
                    } elseif (strpos($name, 'frappe') !== false) {
                        $imgUrl = "https://images.unsplash.com/photo-1572490122747-3968b75cc699?w=150";
                    } elseif (strpos($name, 'milk tea') !== false || strpos($name, 'milktea') !== false) {
                        $imgUrl = "https://images.unsplash.com/photo-1558857563-b371033873b8?w=150";
                    } elseif (strpos($name, 'softdrink') !== false || strpos($name, 'soda') !== false) {
                        $imgUrl = "https://images.unsplash.com/photo-1622483767028-3f66f32aef97?w=150";
                    } elseif (strpos($name, 'juice') !== false) {
                        $imgUrl = "https://images.unsplash.com/photo-1613478223719-2ab802602423?w=150";
                    }
                ?>
                <div class="menu-item-card" data-name="<?= htmlspecialchars(strtolower($item['product_name'])) ?>" data-category="<?= htmlspecialchars($menuCategory) ?>" data-price="<?= $item['price'] ?>" onclick="changeQty(<?= $item['product_id'] ?>, 1)">
                    <div class="circle-img-wrapper">
                        <img src="<?= $imgUrl ?>" alt="<?= htmlspecialchars($item['product_name']) ?>">
                    </div>
                    <div class="item-title"><?= htmlspecialchars($item['product_name']) ?></div>
                    <div class="price-badge">₱<?= number_format($item['price'], 2) ?></div>
                    <div class="add-hint">+ Click to Add</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- DELIVERY ORDER FORM -->
    <div>
        <div class="card">
            <h2>Delivery Order Form</h2>
            <span class="delivery-badge">System Delivery Mode Only</span>

            <?php if (!empty($errors)): ?>
                <div class="alert-error">
                    <?php foreach ($errors as $err): ?>
                        <p style="margin: 2px 0;"><?= htmlspecialchars($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="order_form.php">
                <input type="hidden" name="action" value="place_order">

                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="customer_name" placeholder="e.g. Juan Dela Cruz" required>
                </div>

                <div class="form-group">
                    <label>Phone Number (Required for Delivery Updates) *</label>
                    <input type="tel" name="phone_number" placeholder="e.g. 09123456789" required>
                </div>

                <div class="form-group">
                    <label>Email Address (Optional)</label>
                    <input type="email" name="customer_email" placeholder="e.g. juan@example.com">
                </div>

                <div class="form-group">
                    <label>Delivery Address *</label>
                    <textarea name="delivery_address" rows="2" placeholder="House/Bldg No., Street, City" required></textarea>
                </div>

                <div class="form-group">
                    <label>Payment Method *</label>
                    <select name="payment_method" required>
                        <option value="Cash">Cash on Delivery (COD)</option>
                        <option value="GCash">GCash</option>
                    </select>
                </div>

                <div class="section-heading"><label>Select Quantity &amp; Customize Items:</label><span id="selectedCount">0 items in order</span></div>
                <table class="bulk-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Item Name</th>
                            <th style="width: 15%;">Price</th>
                            <th style="width: 25%;">Quantity</th>
                            <th style="width: 30%;">Customization / Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($menuItems as $item): ?>
                            <tr class="order-row" data-price="<?= $item['price'] ?>">
                                <td><strong><?= htmlspecialchars($item['product_name']) ?></strong><small class="stock-note"><?= $item['stock_quantity'] ?> available</small></td>
                                <td>₱<?= number_format($item['price'], 2) ?></td>
                                <td>
                                    <div class="qty-control-wrapper">
                                        <button type="button" class="qty-btn" onclick="changeQty(<?= $item['product_id'] ?>, -1)">−</button>
                                        <input type="number" 
                                               name="items[<?= $item['product_id'] ?>]" 
                                               id="qty_input_<?= $item['product_id'] ?>" 
                                               class="qty-input" 
                                               min="0" 
                                               max="<?= $item['stock_quantity'] ?>" 
                                               value="0" 
                                               readonly>
                                        <button type="button" class="qty-btn" onclick="changeQty(<?= $item['product_id'] ?>, 1)">+</button>
                                    </div>
                                </td>
                                <td>
                                    <input type="text" 
                                           name="notes[<?= $item['product_id'] ?>]" 
                                           class="note-input" 
                                           placeholder="e.g. Extra cheese, No ice..." 
                                           maxlength="150">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="order-total"><span>Items Total</span><strong id="itemsTotal">₱0.00</strong><span>Delivery Charge</span><strong class="free-charge">FREE</strong><b>Grand Total Amount</b><b id="grandTotal">₱0.00</b></div>
                <button type="submit" class="btn-submit">Submit Delivery Order</button>
            </form>

            <div style="margin-top: 15px; text-align: center;">
                <a href="track_order.php" style="color: #34495e; font-size: 0.9em;">Already placed an order? Track order here →</a>
            </div>
        </div>
    </div>

</div>

<script>
    function updateOrderSummary() {
        var total = 0;
        var itemCount = 0;
        document.querySelectorAll('.order-row').forEach(function (row) {
            var quantityInput = row.querySelector('.qty-input');
            var quantity = parseInt(quantityInput.value, 10) || 0;
            total += quantity * (parseFloat(row.dataset.price) || 0);
            itemCount += quantity;
        });

        document.getElementById('selectedCount').textContent = itemCount + (itemCount === 1 ? ' item' : ' items') + ' in order';
        document.getElementById('itemsTotal').textContent = '₱' + total.toFixed(2);
        document.getElementById('grandTotal').textContent = '₱' + total.toFixed(2);
    }

    function changeQty(productId, amount) {
        var input = document.getElementById('qty_input_' + productId);
        if (input) {
            var maxStock = parseInt(input.getAttribute('max')) || 999;
            var currentVal = parseInt(input.value) || 0;
            var newVal = currentVal + amount;

            if (newVal < 0) newVal = 0;
            if (newVal > maxStock) newVal = maxStock;

            input.value = newVal;

            input.parentElement.style.borderColor = "#d9531e";
            setTimeout(() => { input.parentElement.style.borderColor = "#ccc"; }, 400);
            updateOrderSummary();
        }
    }

    function filterMenu() {
        var query = document.getElementById('menuSearch').value.toLowerCase();
        var activeCategory = document.querySelector('.category-tabs button.active').dataset.category;
        document.querySelectorAll('.menu-item-card').forEach(function (card) {
            var matchesSearch = card.dataset.name.indexOf(query) !== -1;
            var matchesCategory = activeCategory === 'all' || card.dataset.category.indexOf(activeCategory) !== -1;
            card.hidden = !(matchesSearch && matchesCategory);
        });
    }

    document.getElementById('menuSearch').addEventListener('input', filterMenu);
    document.querySelectorAll('.category-tabs button').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelector('.category-tabs button.active').classList.remove('active');
            button.classList.add('active');
            filterMenu();
        });
    });
    document.getElementById('menuSort').addEventListener('change', function () {
        var cards = Array.from(document.querySelectorAll('.menu-item-card'));
        cards.sort(function (a, b) {
            return this.value === 'price'
                ? parseFloat(a.dataset.price) - parseFloat(b.dataset.price)
                : a.dataset.name.localeCompare(b.dataset.name);
        }.bind(this));
        var menuGrid = document.querySelector('.menu-grid');
        cards.forEach(function (card) { menuGrid.appendChild(card); });
    });

    updateOrderSummary();
</script>

</body>
</html>