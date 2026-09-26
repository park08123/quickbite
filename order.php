<?php
abstract class AbstractItem {
    protected int $id;
    protected string $name;
    protected float $price;

    public function __construct(int $id, string $name, float $price) {
        $this->id = $id;
        $this->name = $name;
        $this->price = $price;
    }

    abstract public function getFormattedPrice(): string;
}

class Product extends AbstractItem {
    private int $categoryId;
    private int $stockQuantity;
    private string $status;

    public function __construct(int $id, string $name, float $price, int $categoryId, int $stockQuantity, string $status = 'Available') {
        parent::__construct($id, $name, $price);
        $this->categoryId = $categoryId;
        $this->stockQuantity = $stockQuantity;
        $this->status = $status;
    }

    public function getFormattedPrice(): string {
        return "PHP " . number_format($this->price, 2);
    }

    public static function create(PDO $db, string $name, float $price, int $categoryId, int $stock): bool {
        $stmt = $db->prepare("INSERT INTO products (product_name, price, category_id, stock_quantity) VALUES (:name, :price, :category_id, :stock)");
        return $stmt->execute([
            ':name' => $name,
            ':price' => $price,
            ':category_id' => $categoryId,
            ':stock' => $stock
        ]);
    }

    public static function fetchFiltered(PDO $db, string $search = '', int $categoryId = 0, string $sortBy = 'product_id', string $order = 'DESC', int $limit = 10): array {
        $sql = "SELECT p.*, c.category_name FROM products p JOIN categories c ON p.category_id = c.category_id WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND p.product_name LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }
        if ($categoryId > 0) {
            $sql .= " AND p.category_id = :category_id";
            $params[':category_id'] = $categoryId;
        }

        $allowedSort = ['product_id', 'product_name', 'price', 'stock_quantity'];
        $sortBy = in_array($sortBy, $allowedSort) ? $sortBy : 'product_id';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $sql .= " ORDER BY {$sortBy} {$order} LIMIT " . (int)$limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

class InsufficientStockException extends Exception {}

class Order {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function processOrder(string $customerName, string $customerEmail, array $cartItems): int {
        try {
            $this->db->beginTransaction();

            $totalAmount = 0.00;
            foreach ($cartItems as $item) {
                $stmt = $this->db->prepare("SELECT stock_quantity, price FROM products WHERE product_id = :id FOR UPDATE");
                $stmt->execute([':id' => $item['product_id']]);
                $prod = $stmt->fetch();

                if (!$prod || $prod['stock_quantity'] < $item['quantity']) {
                    throw new InsufficientStockException("Item ID {$item['product_id']} has insufficient stock for this order.");
                }
                $totalAmount += $prod['price'] * $item['quantity'];
            }

            $stmt = $this->db->prepare("INSERT INTO orders (customer_name, customer_email, total_amount) VALUES (:name, :email, :total)");
            $stmt->execute([
                ':name' => $customerName,
                ':email' => $customerEmail,
                ':total' => $totalAmount
            ]);
            $orderId = (int)$this->db->lastInsertId();

            foreach ($cartItems as $item) {
                $stmtItem = $this->db->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (:order_id, :product_id, :qty, :price)");
                $stmtItem->execute([
                    ':order_id' => $orderId,
                    ':product_id' => $item['product_id'],
                    ':qty' => $item['quantity'],
                    ':price' => $item['unit_price']
                ]);

                $stmtUpdate = $this->db->prepare("UPDATE products SET stock_quantity = stock_quantity - :qty WHERE product_id = :product_id");
                $stmtUpdate->execute([
                    ':qty' => $item['quantity'],
                    ':product_id' => $item['product_id']
                ]);
            }

            $this->db->commit();
            return $orderId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}