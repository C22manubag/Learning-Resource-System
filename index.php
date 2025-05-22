<?php
$connect = mysqli_connect("localhost", "root", "", "library_db");
session_start();


if (isset($_POST['reserve']) && isset($_SESSION['UserID'])) {
    $isbn = mysqli_real_escape_string($connect, $_POST['isbn']);
    $userID = $_SESSION['UserID'];

    // Find the BookID first
    $sql_find = "SELECT BookID FROM tblbooks WHERE TRIM(ISBN) = TRIM('$isbn') LIMIT 1";
    $result_find = mysqli_query($connect, $sql_find);

    if ($result_find && mysqli_num_rows($result_find) > 0) {
        $row = mysqli_fetch_assoc($result_find);
        $bookID = $row['BookID'];

        // Insert into transactions
        $sql_insert = "INSERT INTO tbltransactions (UserID, BookID, DateReserved, Status)
                       VALUES ($userID, $bookID, NOW(), 'Reserved')";
        if (mysqli_query($connect, $sql_insert)) {
            echo "<script>alert('Book reserved successfully!');</script>";
        } else {
            echo "<script>alert('Reservation failed: " . mysqli_error($connect) . "');</script>";
        }
    } else {
        echo "<script>alert('Book not found for ISBN: $isbn');</script>";
    }
}




function search($category, $title, $author, $isbn)
{
    global $connect;

    // 1. Fetch all reserved ISBNs first
    $reservedQuery = "SELECT b.ISBN FROM tbltransactions t
                    JOIN tblbooks b ON t.BookID = b.BookID
                    WHERE t.Status = 'Reserved'";
    $reservedResult = mysqli_query($connect, $reservedQuery);

    $reservedISBNs = [];
    if ($reservedResult) {
        while ($r = mysqli_fetch_assoc($reservedResult)) {
            $reservedISBNs[] = trim($r['ISBN']);
        }
        mysqli_free_result($reservedResult);
    }

    // 2. Escape input
    $category = mysqli_real_escape_string($connect, $category);
    $title = mysqli_real_escape_string($connect, $title);
    $author = mysqli_real_escape_string($connect, $author);
    $isbn = mysqli_real_escape_string($connect, $isbn);

    // 3. Call stored procedure
    $sql = "CALL SearchBooks('$category', '$title', '$author', '$isbn')";
    $result = mysqli_query($connect, $sql);
    $no = 1;

    // 4. Display books that are not reserved
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $isbnCheck = trim($row['ISBN']);

            // Skip reserved books
            if (in_array($isbnCheck, $reservedISBNs)) {
                continue;
            }

            echo "<tr>
        <td>{$no}</td>
        <td>" . htmlspecialchars($row['ISBN']) . "</td>
        <td>" . htmlspecialchars($row['Title']) . "</td>
        <td>" . htmlspecialchars($row['Author']) . "</td>
        <td>" . htmlspecialchars($row['Abstract']) . "</td>
        <td>";
            if (isset($_SESSION['UserID'])) {
                echo "<form method='post' style='display:inline;'>
                <input type='hidden' name='isbn' value='" . htmlspecialchars($row['ISBN']) . "'>
                <button type='submit' name='reserve' class='btn btn-success btn-sm'>Reserve</button>
              </form>";
            } else {
                echo "<em>Login to reserve</em>";
            }
            echo "</td></tr>";
            $no++;
        }

        // 5. Free result and move to next if needed
        mysqli_free_result($result);
        mysqli_next_result($connect);
    }
}


function Login($username, $password)
{
    global $connect;

    $username = mysqli_real_escape_string($connect, $username);
    $password = mysqli_real_escape_string($connect, $password);

    $query = "CALL ValidateUser('$username', '$password')";
    $result = mysqli_query($connect, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        $_SESSION['UserID'] = $user['UserID'];
        $_SESSION['Fname'] = $user['Fname'];
        $_SESSION['Role'] = $user['Role'];
        echo "<script>window.location.href = window.location.href;</script>";
    } else {
        echo "<script>alert('Invalid username or password');</script>";
    }
    mysqli_next_result($connect);
}

function Register($fname, $lname, $email, $username, $password)
{
    global $connect;

    $fname = mysqli_real_escape_string($connect, $fname);
    $lname = mysqli_real_escape_string($connect, $lname);
    $email = mysqli_real_escape_string($connect, $email);
    $username = mysqli_real_escape_string($connect, $username);
    $password = mysqli_real_escape_string($connect, $password);

    $check = mysqli_query($connect, "SELECT * FROM tblusers WHERE Username = '$username'");
    if (mysqli_num_rows($check) > 0) {
        echo "<script>alert('Username already taken');</script>";
        return;
    }

    // Call RegisterUser stored procedure (make sure it exists)
    $query = "CALL RegisterUser('$fname', '$lname', '$email', '$username', '$password')";
    if (mysqli_query($connect, $query)) {
        echo "<script>alert('Registration successful! Please log in.');</script>";
    } else {
        echo "<script>alert('Registration failed.');</script>";
    }
    mysqli_next_result($connect);
}

$page = isset($_GET['page']) ? $_GET['page'] : 'search';

$message = '';
if ($page === 'addbooks' && isset($_POST['addbook'])) {
    $isbn = mysqli_real_escape_string($connect, $_POST['isbn']);
    $title = mysqli_real_escape_string($connect, $_POST['title']);
    $author = mysqli_real_escape_string($connect, $_POST['author']);
    $abstract = mysqli_real_escape_string($connect, $_POST['abstract']);
    $category = mysqli_real_escape_string($connect, $_POST['category']);

    if ($isbn && $title && $author) {
        // Changed AddBook to InsertBook to match your existing procedure name
        $sql = "CALL AddBook('$isbn', '$title', '$author', '$abstract', '$category')";
        if (mysqli_query($connect, $sql)) {
            $message = "<div class='alert alert-success'>Book added successfully!</div>";
        } else {
            $message = "<div class='alert alert-danger'>Error adding book: " . mysqli_error($connect) . "</div>";
        }
        mysqli_next_result($connect);
    } else {
        $message = "<div class='alert alert-warning'>Please fill at least ISBN, Title, and Author.</div>";
    }
}

// Handle login and register POST
if (isset($_POST['login'])) {
    Login($_POST['uname'], $_POST['pwd']);
}
if (isset($_POST['register'])) {
    Register($_POST['fname'], $_POST['lname'], $_POST['email'], $_POST['uname'], $_POST['pwd']);
}



function renderMyReservations()
{
    global $connect;

    if (!isset($_SESSION['UserID'])) {
        echo "<p>Please login to view your reservations.</p>";
        return;
    }

    $userID = $_SESSION['UserID'];

    $sql = "SELECT b.Title, b.ISBN, t.DateReserved, t.Status,
                   DATEDIFF(CURDATE(), DATE(t.DateReserved)) AS DaysReserved
            FROM tbltransactions t
            JOIN tblbooks b ON t.BookID = b.BookID
            WHERE t.UserID = $userID AND t.Status = 'Reserved'
            ORDER BY t.DateReserved DESC";

    $result = mysqli_query($connect, $sql);

    if (!$result || mysqli_num_rows($result) == 0) {
        echo "<p>No active reservations found.</p>";
        return;
    }

    echo '<h3>My Reserved Books</h3>';
    echo '<table class="table table-bordered">';
    echo '<thead><tr><th>No.</th><th>ISBN</th><th>Title</th><th>Date Reserved</th><th>Status</th><th>Days Reserved</th><th>Indicator</th></tr></thead><tbody>';

    $no = 1;
    while ($row = mysqli_fetch_assoc($result)) {
        $days = (int) $row['DaysReserved'];
        $color = 'green';   // default green
        $statusText = "Active";

        if ($days >= 6) {
            $color = 'red';
            $statusText = "Nearing Expiry";
        } elseif ($days == 5) {
            $color = 'yellow';
            $statusText = "Warning";
        }

        echo '<tr>';
        echo '<td>' . $no++ . '</td>';
        echo '<td>' . htmlspecialchars($row['ISBN']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Title']) . '</td>';
        echo '<td>' . htmlspecialchars($row['DateReserved']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Status']) . '</td>';
        echo '<td>' . $days . '</td>';
        echo '<td><span style="display:inline-block;width:20px;height:20px;background-color:' . $color . ';border-radius:50%;"></span> ' . $statusText . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}


?>



<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Library System</title>
    <link href="css/bootstrap.min.css" rel="stylesheet" />
</head>

<body>
    <div class="container">
        <div class="pull-left">
            <h1>Learning Resource System</h1>
        </div>
        <div class="pull-right">
            <?php
            if (isset($_SESSION['UserID']) && isset($_SESSION['Fname'])) {
                echo "<h1>Welcome " . htmlspecialchars($_SESSION['Fname']) . "</h1>";
                echo "<span class='text-right'>You are logged in as " . htmlspecialchars($_SESSION['Role']) . "</span>";
            } else {
                echo "<h1>Welcome Guest</h1>";
            }
            ?>
        </div>
    </div>

    <div class="container">
        <nav class="navbar navbar-inverse">
            <div class="container-fluid">
                <ul class="nav navbar-nav">
                    <?php if (isset($_SESSION['Role']) && $_SESSION['Role'] === 'User'): ?>
                        <li class="<?= $page === 'listbooks' ? 'active' : '' ?>"><a href="?page=listbooks">List of Books</a>
                        </li>
                        <li class="<?= $page === 'search' ? 'active' : '' ?>"><a href="?page=search">Search and Reserve</a>
                        </li>
                        <li class="<?= $page === 'myreservations' ? 'active' : '' ?>"><a href="?page=myreservations">My
                                Reservations</a></li>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['Role']) && $_SESSION['Role'] === 'Admin'): ?>
                        <li class="<?= $page === 'listbooks' ? 'active' : '' ?>"><a href="?page=listbooks">List of Books</a>
                        </li>
                        <li class="<?= $page === 'transaction' ? 'active' : '' ?>"><a href="?page=transaction">Transaction
                                Record</a></li>
                        <li class="<?= $page === 'users' ? 'active' : '' ?>"><a href="?page=users">Manage Users</a></li>
                        <li class="<?= $page === 'addbooks' ? 'active' : '' ?>"><a href="?page=addbooks">Add Books</a></li>
                    <?php endif; ?>
                </ul>

                <ul class="nav navbar-nav navbar-right">
                    <?php
                    if (isset($_SESSION['UserID']) && isset($_SESSION['Fname'])) {
                        echo '<li><a href="logout.php"><span class="glyphicon glyphicon-log-in"></span> Logout</a></li>';
                    } else {
                        echo '<li><a href="#loginForm" data-toggle="modal"><span class="glyphicon glyphicon-log-in"></span> Login</a></li>';
                    }
                    ?>
                </ul>
            </div>
        </nav>


        <?php
        if ($page === 'myreservations') {
            renderMyReservations();
        } elseif ($page === 'search') {
            ?>
            <div class="row">
                <div class="col-md-8">
                    <h3>Book Information</h3>
                    <table class="table table-bordered">
                        <tr>
                            <th>No.</th>
                            <th>ISBN</th>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Abstract</th>
                            <th>Action</th>
                        </tr>
                        <?php
                        if (isset($_POST['search'])) {
                            search($_POST['category'], $_POST['title'], $_POST['author'], $_POST['isbn']);
                        }
                        ?>
                    </table>
                </div>
                <div class="col-md-4">
                    <h3>Filter</h3>
                    <form method="post" action="?page=search">
                        <div class="form-group">
                            <label for="category">Category:</label>
                            <select name="category" id="category" class="form-control">
                                <option value=""> -- SELECT BELOW -- </option>
                                <option value="All" <?= (isset($_POST['category']) && $_POST['category'] == 'All') ? 'selected' : '' ?>> All </option>
                                <?php
                                $sql = "SELECT DISTINCT(Category) FROM tblbooks WHERE Category NOT IN ('') ORDER BY Category ASC";
                                $query = mysqli_query($connect, $sql);
                                while ($result = mysqli_fetch_array($query)) {
                                    $selected = (isset($_POST['category']) && $_POST['category'] == $result['Category']) ? 'selected' : '';
                                    echo "<option value='" . htmlspecialchars($result['Category']) . "' $selected>" . htmlspecialchars($result['Category']) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="title">Title:</label>
                            <input type="text" name="title" class="form-control"
                                value="<?= isset($_POST['title']) ? htmlspecialchars($_POST['title']) : '' ?>" id="title">
                        </div>
                        <div class="form-group">
                            <label for="author">Author:</label>
                            <input type="text" name="author" class="form-control"
                                value="<?= isset($_POST['author']) ? htmlspecialchars($_POST['author']) : '' ?>"
                                id="author">
                        </div>
                        <div class="form-group">
                            <label for="isbn">ISBN:</label>
                            <input type="number" name="isbn" class="form-control"
                                value="<?= isset($_POST['isbn']) ? htmlspecialchars($_POST['isbn']) : '' ?>" id="isbn">
                        </div>
                        <div class="form-group">
                            <button type="submit" name="search" class="btn btn-primary">Search</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php
        } elseif ($page === 'addbooks') {
            ?>
            <h3>Add New Book</h3>
            <?= $message ?>
            <form method="post" action="?page=addbooks">
                <div class="form-group">
                    <label for="isbn">ISBN (required):</label>
                    <input type="text" name="isbn" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="title">Title (required):</label>
                    <input type="text" name="title" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="author">Author (required):</label>
                    <input type="text" name="author" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="abstract">Abstract:</label>
                    <textarea name="abstract" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label for="category">Category:</label>
                    <input type="text" name="category" class="form-control">
                </div>
                <button type="submit" name="addbook" class="btn btn-primary">Add Book</button>
            </form>
            <?php
        } elseif ($page === 'transaction') {
            ?>
            <h3>Transaction Record</h3>
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>User</th>
                        <th>Book</th>
                        <th>Date Reserved</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT t.TransactionID, u.Fname, u.Lname, b.Title, t.DateReserved, t.Status 
              FROM tbltransactions t
              JOIN tblusers u ON t.UserID = u.UserID
              JOIN tblbooks b ON t.BookID = b.BookID
              ORDER BY t.DateReserved DESC";
                    $result = mysqli_query($connect, $sql);

                    if ($result) {
                        $no = 1;
                        while ($row = mysqli_fetch_assoc($result)) {
                            echo "<tr>
                  <td>{$no}</td>
                  <td>" . htmlspecialchars($row['Fname'] . ' ' . $row['Lname']) . "</td>
                  <td>" . htmlspecialchars($row['Title']) . "</td>
                  <td>" . htmlspecialchars($row['DateReserved']) . "</td>
                  <td>" . htmlspecialchars($row['Status']) . "</td>
                </tr>";
                            $no++;
                        }
                    } else {
                        echo "<tr><td colspan='5'>No transactions found.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
            <?php
        } elseif ($page === 'users') {
            ?>
            <h3>Manage Users</h3>
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Email</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Date Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT * FROM tblusers ORDER BY DateCreated DESC";
                    $result = mysqli_query($connect, $sql);
                    $no = 1;
                    while ($row = mysqli_fetch_assoc($result)) {
                        echo "<tr>
                <td>{$no}</td>
                <td>" . htmlspecialchars($row['Fname']) . "</td>
                <td>" . htmlspecialchars($row['Lname']) . "</td>
                <td>" . htmlspecialchars($row['Email']) . "</td>
                <td>" . htmlspecialchars($row['Username']) . "</td>
                <td>" . htmlspecialchars($row['Role']) . "</td>
                <td>" . htmlspecialchars($row['DateCreated']) . "</td>
              </tr>";
                        $no++;
                    }
                    ?>
                </tbody>
            </table>
            <?php
        } elseif ($page === 'listbooks') {
            ?>
            <h3>All Books</h3>
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>ISBN</th>
                        <th>Title</th>
                        <th>Author</th>
                        <th>Abstract</th>
                        <th>Category</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT * FROM tblbooks ORDER BY Title ASC";
                    $result = mysqli_query($connect, $sql);
                    $no = 1;
                    while ($row = mysqli_fetch_assoc($result)) {
                        echo "<tr>
                <td>{$no}</td>
                <td>" . htmlspecialchars($row['ISBN']) . "</td>
                <td>" . htmlspecialchars($row['Title']) . "</td>
                <td>" . htmlspecialchars($row['Author']) . "</td>
                <td>" . htmlspecialchars($row['Abstract']) . "</td>
                <td>" . htmlspecialchars($row['Category']) . "</td>
              </tr>";
                        $no++;
                    }
                    ?>
                </tbody>
            </table>
            <?php
        } else {
            echo "<p>Page not found.</p>";
        }
        ?>




    </div>

    <!-- Login Modal -->
    <div id="loginForm" class="modal fade" role="dialog">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body">
                    <form method="post">
                        <div class="form-group">
                            <label for="uname">Username:</label>
                            <input type="text" name="uname" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="pwd">Password:</label>
                            <input type="password" name="pwd" class="form-control" required>
                        </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="login" class="btn btn-primary">Login</button>
                    <button type="button" class="btn btn-success" data-toggle="modal" data-target="#registerForm"
                        data-dismiss="modal">Register</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Register Modal -->
    <div id="registerForm" class="modal fade" role="dialog">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body">
                    <form method="post">
                        <div class="form-group">
                            <label for="fname">First Name:</label>
                            <input type="text" name="fname" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="lname">Last Name:</label>
                            <input type="text" name="lname" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email:</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="uname">Username:</label>
                            <input type="text" name="uname" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="pwd">Password:</label>
                            <input type="password" name="pwd" class="form-control" required>
                        </div>
                </div>
                <div>
                    <class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="register" class="btn btn-primary">Register</button>
                </div>
                </form>
            </div>
        </div>

    </div>
    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</body>

</html>