<?php
require 'config.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('index.php');
}

$msg = null;

/* ---------- ADD BOOK ---------- */
if (isset($_POST['add_book'])) {
    $title   = trim($_POST['title']);
    $author  = trim($_POST['author']);
    $cat     = trim($_POST['category']);
    $desc    = trim($_POST['description']);
    $copies  = (int)$_POST['total_copies'];

    if ($title === '' || $author === '' || $cat === '' || $copies <= 0) {
        $msg = ['type' => 'error', 'text' => 'Title, author, category and copies are required.'];
    } else {
        $stmt = $conn->prepare("INSERT INTO books(title,author,category,description,total_copies,available_copies) VALUES(?,?,?,?,?,?)");
        $stmt->bind_param("ssssii", $title, $author, $cat, $desc, $copies, $copies);
        if ($stmt->execute()) {
            $msg = ['type' => 'success', 'text' => 'Book added successfully.'];
        } else {
            $msg = ['type' => 'error', 'text' => 'Error while adding book.'];
        }
    }
}

/* ---------- DELETE BOOK ---------- */
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $conn->query("DELETE FROM books WHERE id=$id");
    $msg = ['type' => 'success', 'text' => 'Book deleted.'];
}

/* ---------- EDIT / UPDATE BOOK ---------- */
$editBook = null;
if (isset($_GET['edit_id'])) {
    $id = (int)$_GET['edit_id'];
    $res = $conn->query("SELECT * FROM books WHERE id=$id");
    if ($res->num_rows === 1) {
        $editBook = $res->fetch_assoc();
    }
}

if (isset($_POST['update_book'])) {
    $id      = (int)$_POST['id'];
    $title   = trim($_POST['title']);
    $author  = trim($_POST['author']);
    $cat     = trim($_POST['category']);
    $desc    = trim($_POST['description']);
    $total   = (int)$_POST['total_copies'];
    $avail   = (int)$_POST['available_copies'];

    if ($title === '' || $author === '' || $cat === '' || $total <= 0 || $avail < 0) {
        $msg = ['type' => 'error', 'text' => 'Please fill all fields correctly.'];
    } else {
        $stmt = $conn->prepare("UPDATE books SET title=?, author=?, category=?, description=?, total_copies=?, available_copies=? WHERE id=?");
        $stmt->bind_param("ssssiis", $title, $author, $cat, $desc, $total, $avail, $id);
        if ($stmt->execute()) {
            $msg = ['type' => 'success', 'text' => 'Book updated successfully.'];
        } else {
            $msg = ['type' => 'error', 'text' => 'Error while updating book.'];
        }
    }
}

/* ---------- MARK RETURN (ADMIN) ---------- */
if (isset($_GET['return_borrow_id'])) {
    $borrowId = (int)$_GET['return_borrow_id'];

    $stmt = $conn->prepare("SELECT * FROM borrows WHERE id=? AND status='issued'");
    $stmt->bind_param("i", $borrowId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 1) {
        $br = $res->fetch_assoc();
        $bookId = $br['book_id'];
        $now  = date('Y-m-d H:i:s');
        $fine = calculateFine($br['due_date'], date('Y-m-d'));

        $conn->begin_transaction();
        try {
            $up = $conn->prepare("UPDATE borrows SET status='returned', returned_at=?, fine_amount=? WHERE id=?");
            $up->bind_param("sdi", $now, $fine, $borrowId);
            $up->execute();

            $conn->query("UPDATE books SET available_copies = available_copies + 1 WHERE id=$bookId");
            $conn->commit();

            $msg = ['type' => 'success', 'text' => "Book marked as returned. Fine: ₹$fine"];
        } catch (Exception $e) {
            $conn->rollback();
            $msg = ['type' => 'error', 'text' => 'Error while marking as returned.'];
        }
    }
}

/* ---------- DASHBOARD STATS ---------- */
$stats = [
    'total_books' => 0,
    'total_users' => 0,
    'total_issued' => 0,
    'total_returned' => 0,
    'pending_returns' => 0,
    'total_fine' => 0
];

$res1 = $conn->query("SELECT COUNT(*) AS c FROM books");
$stats['total_books'] = $res1->fetch_assoc()['c'];

$res2 = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='user'");
$stats['total_users'] = $res2->fetch_assoc()['c'];

$res3 = $conn->query("SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status='returned' THEN 1 ELSE 0 END) AS returned,
        SUM(CASE WHEN status='issued' THEN 1 ELSE 0 END) AS pending,
        IFNULL(SUM(fine_amount),0) AS fine
    FROM borrows");
$row3 = $res3->fetch_assoc();
$stats['total_issued']   = $row3['total'];
$stats['total_returned'] = $row3['returned'];
$stats['pending_returns']= $row3['pending'];
$stats['total_fine']     = $row3['fine'];

/* ---------- BOOKS + ISSUED LIST ---------- */
$books = $conn->query("SELECT * FROM books ORDER BY created_at DESC");

$borrowed = $conn->query("
    SELECT br.id, u.full_name, u.email, b.title, br.issued_at, br.due_date, br.status, br.fine_amount
    FROM borrows br
    JOIN users u ON br.user_id = u.id
    JOIN books b ON br.book_id = b.id
    ORDER BY br.issued_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin - Digital Library</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <div class="logo">
        <div class="topi">🎓</div>
        <div class="logo-text">Admin Panel</div>
    </div>
    <nav>
        <ul>
            <li><a href="index.php">User View</a></li>
            <li><a href="#manage">Books</a></li>
            <li><a href="#issued">Issued</a></li>
            <li><a href="logout.php">Logout (<?php echo htmlspecialchars($_SESSION['user_name']); ?>)</a></li>
        </ul>
    </nav>
</header>

<section class="hero" id="top">
    <h1>Library Admin</h1>
    <h2>Manage Books & Issued Records</h2>
    <p>Add, edit, or delete books and see which books are currently issued.</p>
</section>

<section id="dashboard">
    <h3 class="section-title">Dashboard Overview</h3>

    <?php if (isset($msg)): ?>
        <div class="message <?php echo $msg['type']; ?>">
            <?php echo htmlspecialchars($msg['text']); ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-title">Total Books</div>
            <div class="stat-value"><?php echo $stats['total_books']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Total Users</div>
            <div class="stat-value"><?php echo $stats['total_users']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Total Issued</div>
            <div class="stat-value"><?php echo $stats['total_issued']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Returned</div>
            <div class="stat-value"><?php echo $stats['total_returned']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Pending Returns</div>
            <div class="stat-value"><?php echo $stats['pending_returns']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Total Fine Collected</div>
            <div class="stat-value">₹<?php echo number_format($stats['total_fine'], 2); ?></div>
        </div>
    </div>
</section>

<section id="manage">
    <h3 class="section-title">Manage Books</h3>

    <div class="form-wrapper">
        <div class="form-title"><?php echo $editBook ? 'Edit Book' : 'Add New Book'; ?></div>
        <form method="post">
            <?php if ($editBook): ?>
                <input type="hidden" name="id" value="<?php echo $editBook['id']; ?>">
            <?php endif; ?>
            <div class="input-group">
                <input type="text" name="title" placeholder="Title"
                       value="<?php echo $editBook ? htmlspecialchars($editBook['title']) : ''; ?>">
            </div>
            <div class="input-group">
                <input type="text" name="author" placeholder="Author"
                       value="<?php echo $editBook ? htmlspecialchars($editBook['author']) : ''; ?>">
            </div>
            <div class="input-group">
                <input type="text" name="category" placeholder="Category"
                       value="<?php echo $editBook ? htmlspecialchars($editBook['category']) : ''; ?>">
            </div>
            <div class="input-group">
                <textarea name="description" rows="3" placeholder="Short description (optional)"><?php
                    echo $editBook ? htmlspecialchars($editBook['description']) : '';
                ?></textarea>
            </div>
            <div class="input-group">
                <input type="number" name="total_copies" placeholder="Total Copies"
                       value="<?php echo $editBook ? $editBook['total_copies'] : 1; ?>">
            </div>
            <?php if ($editBook): ?>
                <div class="input-group">
                    <input type="number" name="available_copies" placeholder="Available Copies"
                           value="<?php echo $editBook['available_copies']; ?>">
                </div>
            <?php endif; ?>
            <button class="btn" type="submit" name="<?php echo $editBook ? 'update_book' : 'add_book'; ?>">
                <?php echo $editBook ? 'Update Book' : 'Add Book'; ?>
            </button>
        </form>
    </div>

    <h3 class="section-title" style="margin-top:30px;">All Books</h3>
    <table>
        <thead>
        <tr>
            <th>Title</th>
            <th>Author</th>
            <th>Category</th>
            <th>Total</th>
            <th>Available</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($books->num_rows == 0): ?>
            <tr><td colspan="6">No books added yet.</td></tr>
        <?php else: ?>
            <?php while ($row = $books->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                    <td><?php echo htmlspecialchars($row['author']); ?></td>
                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                    <td><?php echo $row['total_copies']; ?></td>
                    <td><?php echo $row['available_copies']; ?></td>
                    <td>
                        <a href="admin.php?edit_id=<?php echo $row['id']; ?>#manage">Edit</a> |
                        <a href="admin.php?delete_id=<?php echo $row['id']; ?>"
                           onclick="return confirm('Delete this book?');">Delete</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<section id="issued">
    <h3 class="section-title">Issued Books</h3>
    <table>
        <thead>
        <tr>
            <th>User</th>
            <th>Email</th>
            <th>Book</th>
            <th>Issued On</th>
            <th>Due Date</th>
            <th>Status</th>
            <th>Fine</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($borrowed->num_rows == 0): ?>
            <tr><td colspan="8">No books issued yet.</td></tr>
        <?php else: ?>
            <?php while ($row = $borrowed->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                    <td><?php echo $row['issued_at']; ?></td>
                    <td><?php echo $row['due_date']; ?></td>
                    <td><?php echo ucfirst($row['status']); ?></td>
                    <td>₹<?php echo number_format($row['fine_amount'], 2); ?></td>
                    <td>
                        <?php if ($row['status'] === 'issued'): ?>
                            <a class="btn secondary"
                               href="admin.php?return_borrow_id=<?php echo $row['id']; ?>#issued"
                               onclick="return confirm('Mark as returned?');">
                                Mark Returned
                            </a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<footer>
    © 2025 Library Management System | Admin Panel
</footer>

</body>
</html>