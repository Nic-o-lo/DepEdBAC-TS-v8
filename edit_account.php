<?php
session_start();
require 'config.php';

// User must be logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['userID'];
$error = "";
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['password']);

    if (empty($password)) {
        $error = "Password cannot be empty.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE tbluser SET password = ? WHERE userID = ?");
            $stmt->execute([$password, $userID]);
            $success = true;
        } catch (PDOException $e) {
            $error = "Error updating password: " . $e->getMessage();
        }
    }
}

// Fetch current user info
$stmt = $pdo->prepare("SELECT u.*, o.officename FROM tbluser u LEFT JOIN officeid o ON u.officeID = o.officeID WHERE u.userID = ?");
$stmt->execute([$userID]);
$user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Edit Account - DepEd BAC Tracking System</title>
  <link rel="stylesheet" href="assets/css/home.css" />
  <style>
    .modal {
      display: block;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4);
    }
    .modal-content {
      background-color: #fefefe;
      margin: 8% auto;
      padding: 20px;
      border: 1px solid #888;
      width: 90%;
      max-width: 500px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    .close {
      color: #aaa;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }
    .close:hover {
      color: black;
    }
    form label {
      display: block;
      margin-top: 10px;
    }
    form input[type="text"],
    form input[type="password"] {
      width: 100%;
      padding: 8px;
      margin-top: 4px;
      box-sizing: border-box;
    }
    form button {
      margin-top: 15px;
      padding: 10px;
      width: 100%;
      border: none;
      background-color: #0d47a1;
      color: white;
      font-weight: bold;
      border-radius: 4px;
      cursor: pointer;
    }
  </style>
</head>
<body>
  <!-- Header -->
  <div class="header">
    <img src="assets/images/DEPED-LAOAG_SEAL_Glow.png" alt="DepEd Logo" class="header-logo" />
    <div class="header-text">
      <div class="title-left">
        SCHOOLS DIVISION OF LAOAG CITY<br />DEPARTMENT OF EDUCATION
      </div>
    </div>
    <div class="user-menu">
      <span class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
      <div class="dropdown">
        <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png" alt="User Icon" class="user-icon" />
        <div class="dropdown-content">
          <a href="logout.php">Log out</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal -->
  <div class="modal">
    <div class="modal-content">
      <span class="close" onclick="window.location.href='index.php'">&times;</span>
      <h2>Edit Account</h2>

      <?php if ($error): ?>
        <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <?php if ($success): ?>
        <h3>Password Updated Successfully!</h3>
        <button onclick="window.location.href='index.php'">Return to Dashboard</button>
      <?php else: ?>
      <form method="post">
        <label>First Name</label>
        <input type="text" value="<?= htmlspecialchars($user['firstname']) ?>" disabled />

        <label>Middle Name</label>
        <input type="text" value="<?= htmlspecialchars($user['middlename']) ?>" disabled />

        <label>Last Name</label>
        <input type="text" value="<?= htmlspecialchars($user['lastname']) ?>" disabled />

        <label>Position</label>
        <input type="text" value="<?= htmlspecialchars($user['position']) ?>" disabled />

        <label>Username</label>
        <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled />

        <label>Office</label>
        <input type="text" value="<?= htmlspecialchars($user['officename']) ?>" disabled />

        <label>New Password*</label>
        <input type="password" name="password" required />

        <button type="submit">Update Password</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
