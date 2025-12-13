<?php
require 'config.php';

$msg = null;

/* ---------- REGISTER ---------- */
if (isset($_POST['register'])) {
    $name  = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $pass  = trim($_POST['password']);

    if ($name === '' || $email === '' || $pass === '') {
        $msg = ['type' => 'error', 'text' => 'All fields are required for registration.'];
    } else {
        $hash = hash('sha256', $pass);
        $stmt = $conn->prepare("INSERT INTO users(full_name,email,password,role) VALUES(?,?,?,'user')");
        $stmt->bind_param("sss", $name, $email, $hash);
        if ($stmt->execute()) {
            $msg = ['type' => 'success', 'text' => 'Registration successful! You can login now.'];
        } else {
            $msg = ['type' => 'error', 'text' => 'Email already exists or error occurred.'];
        }
    }
}

/* ---------- LOGIN (user + admin) ---------- */
if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $pass  = trim($_POST['password']);
    $hash  = hash('sha256', $pass);

    $stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE email=? AND password=?");
    $stmt->bind_param("ss", $email, $hash);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];

        if ($user['role'] === 'admin') {
            redirect('admin.php');
        }
        else {
            redirect('index.php'); // refresh hide login/register
        }
    } else {
        $msg = ['type' => 'error', 'text' => 'Invalid email or password.'];
    }
}

/* ---------- BORROW (ISSUE BOOK) ---------- */
if (!isAdmin() && isset($_GET['borrow_id']) && isLoggedIn()) {

    $bookId = (int)$_GET['borrow_id'];

    $bq = $conn->prepare("SELECT available_copies FROM books WHERE id=?");
    $bq->bind_param("i", $bookId);
    $bq->execute();
    $bRes = $bq->get_result();

    if ($bRes->num_rows === 1) {
        $book = $bRes->fetch_assoc();
        if ($book['available_copies'] > 0) {
            $userId = $_SESSION['user_id'];
            $due = date('Y-m-d', strtotime('+7 days'));

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO borrows(user_id, book_id, due_date) VALUES(?,?,?)");
                $stmt->bind_param("iis", $userId, $bookId, $due);
                $stmt->execute();

                $conn->query("UPDATE books SET available_copies = available_copies - 1 WHERE id=$bookId");

                $conn->commit();
                $msg = ['type' => 'success', 'text' => 'Book issued successfully!'];
            }
            catch (Exception $e) {
                $conn->rollback();
                $msg = ['type' => 'error', 'text' => 'Error while issuing book.'];
            }
        } else {
            $msg = ['type' => 'error', 'text' => 'No copies available for this book.'];
        }
    }
}

/* ---------- RETURN BOOK (USER) ---------- */
if (isset($_GET['return_borrow_id']) && isLoggedIn() && !isAdmin()) {

    $borrowId = (int)$_GET['return_borrow_id'];
    $userId   = $_SESSION['user_id'];

    $stmt = $conn->prepare("SELECT * FROM borrows WHERE id=? AND user_id=? AND status='issued'");
    $stmt->bind_param("ii", $borrowId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {

        $br    = $res->fetch_assoc();
        $bookId = $br['book_id'];
        $now    = date('Y-m-d H:i:s');
        $fine   = calculateFine($br['due_date'], date('Y-m-d'));

        $conn->begin_transaction();
        try {
            $up = $conn->prepare("UPDATE borrows SET status='returned', returned_at=?, fine_amount=? WHERE id=?");
            $up->bind_param("sdi", $now, $fine, $borrowId);
            $up->execute();

            $conn->query("UPDATE books SET available_copies = available_copies + 1 WHERE id=$bookId");

            $conn->commit();

            $msg = $fine > 0
                ? ['type' => 'error', 'text' => "Returned late! Fine: ₹$fine"]
                : ['type' => 'success', 'text' => "Book returned successfully!"];
        }
        catch (Exception $e) {
            $conn->rollback();
            $msg = ['type' => 'error', 'text' => 'Error while returning book'];
        }
    }
}

/* ---------- SEARCH + FILTER ---------- */
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$catFilter = isset($_GET['cat']) ? trim($_GET['cat']) : '';

$sql = "SELECT * FROM books WHERE 1";
$params = [];
$types  = "";

if ($search !== '') {
    $sql .= " AND (title LIKE ? OR author LIKE ? OR category LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= "sss";
}

if ($catFilter !== '') {
    $sql .= " AND category = ?";
    $params[] = $catFilter;
    $types    .= "s";
}

$sql .= " ORDER BY created_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $books = $stmt->get_result();
} else {
    $books = $conn->query($sql);
}

$catsRes = $conn->query("SELECT DISTINCT category FROM books ORDER BY category ASC");

/* ---------- USER BORROW LIST + STATS ---------- */
$userBorrows = null;
$userStats   = null;

if (isLoggedIn() && !isAdmin()) {

    $uid = $_SESSION['user_id'];

    $userBorrows = $conn->query("
        SELECT br.*, b.title, b.author
        FROM borrows br
        JOIN books b ON br.book_id = b.id
        WHERE br.user_id = $uid
        ORDER BY br.issued_at DESC
    ");

    $stats = $conn->query("
        SELECT
          COUNT(*) AS total_issues,
          SUM(CASE WHEN status='issued' THEN 1 ELSE 0 END) AS active_issues,
          IFNULL(SUM(fine_amount),0) AS total_fine
        FROM borrows
        WHERE user_id = $uid
    ");

    $userStats = $stats->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Digital Library</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <div class="logo">
        <div class="topi">🎓</div>
        <div class="logo-text">Digital Library</div>
    </div>
    <nav>
        <ul>
            <li><a href="#home">Home</a></li>
            <li><a href="#books">Books</a></li>
            <?php if (!isLoggedIn()): ?>
                <li><a href="#login">Login</a></li>
                <li><a href="#register">Register</a></li>
            <?php else: ?>
                <?php if (!isAdmin()): ?>
                    <li><a href="#profile">My Profile</a></li>
                    <li><a href="#borrowed">My Books</a></li>
                <?php else: ?>
                    <li><a href="admin.php">Admin Panel</a></li>
                <?php endif; ?>
                <li><a href="logout.php">Logout (<?php echo htmlspecialchars($_SESSION['user_name']); ?>)</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</header>

<section id="home" class="hero">
    <h1>Welcome to Our Digital Library</h1>
    <h2></h2>
    <p>Manage, Borrow, and Explore thousands of books online. Your smart library for a smarter world.</p>
</section>

<section id="books">
    <h3 class="section-title">Available Books</h3>

    <?php if ($msg): ?>
        <div class="message <?php echo $msg['type']; ?>">
            <?php echo htmlspecialchars($msg['text']); ?>
        </div>
    <?php endif; ?>

    <form method="get" class="search-bar">
        <input type="text" name="q" placeholder="Search by title, author, category" value="<?php echo htmlspecialchars($search); ?>">
        <select name="cat">
            <option value="">All Categories</option>
            <?php while ($c = $catsRes->fetch_assoc()): ?>
                <option value="<?php echo $c['category']; ?>"
                    <?php echo $catFilter === $c['category'] ? 'selected' : ''; ?>>
                    <?php echo $c['category']; ?>
                </option>
            <?php endwhile; ?>
        </select>
        <button class="btn">Search</button>
    </form>

    <div class="books-container">
        <?php if ($books->num_rows == 0): ?>
            <p>No books found.</p>
        <?php else: ?>
            <?php while ($row = $books->fetch_assoc()): ?>
                <div class="book-card">
                    <div class="book-title"><?php echo $row['title']; ?></div>
                    <div class="book-meta">By <?php echo $row['author']; ?></div>
                    <div class="book-meta">Category: <?php echo $row['category']; ?></div>

                    <div class="book-meta">
                        <span class="badge">Available: <?php echo $row['available_copies']; ?>/<?php echo $row['total_copies']; ?></span>
                    </div>

                    <?php if (isLoggedIn() && !isAdmin()): ?>
                        <a class="btn"
                           href="?borrow_id=<?php echo $row['id']; ?>#books"
                           <?php echo $row['available_copies'] <= 0 ? 'style="background:#bbb;" disabled' : ''; ?>>
                            Borrow
                        </a>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>
</section>

<?php if (!isLoggedIn()): ?>
<section id="login">
    <div class="form-wrapper">
        <div class="form-title">Login</div>
        <form method="post">
            <div class="input-group"><input type="email" name="email" placeholder="Email"></div>
            <div class="input-group"><input type="password" name="password" placeholder="Password"></div>
            <button class="btn" name="login">Login</button>
        </form>
    </div>
</section>
<?php endif; ?>

<?php if (!isLoggedIn()): ?>
<section id="register">
    <div class="form-wrapper">
        <div class="form-title">Register</div>
        <form method="post">
            <div class="input-group"><input type="text" name="full_name" placeholder="Full Name"></div>
            <div class="input-group"><input type="email" name="email" placeholder="Email"></div>
            <div class="input-group"><input type="password" name="password" placeholder="Password"></div>
            <button class="btn" name="register">Register</button>
        </form>
    </div>
</section>
<?php endif; ?>

<?php if (isLoggedIn() && !isAdmin()): ?>
<section id="profile">
    <h3 class="section-title">My Profile</h3>
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-title">Name</div><div class="stat-value" style="font-size:18px;"><?php echo $_SESSION['user_name']; ?></div></div>
        <div class="stat-card"><div class="stat-title">Issued Total</div><div class="stat-value"><?php echo $userStats['total_issues']; ?></div></div>
        <div class="stat-card"><div class="stat-title">Currently Borrowed</div><div class="stat-value"><?php echo $userStats['active_issues']; ?></div></div>
        <div class="stat-card"><div class="stat-title">Total Fine</div><div class="stat-value">₹<?php echo number_format($userStats['total_fine'],2); ?></div></div>
    </div>
</section>

<section id="borrowed">
    <h3 class="section-title">My Borrowed Books</h3>
    <?php if ($userBorrows->num_rows == 0): ?>
        <p>No borrowed books yet.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Book</th><th>Author</th><th>Issued</th><th>Due</th><th>Status</th><th>Fine</th><th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = $userBorrows->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $row['title']; ?></td>
                    <td><?php echo $row['author']; ?></td>
                    <td><?php echo $row['issued_at']; ?></td>
                    <td><?php echo $row['due_date']; ?></td>
                    <td><?php echo ucfirst($row['status']); ?></td>
                    <td>₹<?php echo number_format($row['fine_amount'],2); ?></td>
                    <td>
                        <?php if ($row['status'] === 'issued'): ?>
                            <a class="btn secondary"
                               href="?return_borrow_id=<?php echo $row['id']; ?>#borrowed"
                               onclick="return confirm('Return this book?');">Return</a>
                        <?php else: ?>- <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php endif; ?>

<footer>
    
</footer>

</body>
</html>