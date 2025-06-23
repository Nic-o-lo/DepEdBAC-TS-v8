<?php
session_start();
require 'config.php'; // Ensure your PDO connection is set up correctly

// Redirect if user is not logged in.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Define the ordered list of stages and their short forms for status display.
$stagesOrder = [
    'Purchase Request'      => 'PR',
    'RFQ 1'                 => 'RFQ1',
    'RFQ 2'                 => 'RFQ2',
    'RFQ 3'                 => 'RFQ3',
    'Abstract of Quotation' => 'AoQ',
    'Purchase Order'        => 'PO',
    'Notice of Award'       => 'NoA',
    'Notice to Proceed'     => 'NtP'
];

/* ---------------------------
   Project Deletion (Admin Only)
------------------------------ */
if (isset($_GET['deleteProject']) && $_SESSION['admin'] == 1) {
    $delID = intval($_GET['deleteProject']);
    try {
        $pdo->beginTransaction();
        // Delete associated stages first
        $stmtDelStages = $pdo->prepare("DELETE FROM tblproject_stages WHERE projectID = ?");
        $stmtDelStages->execute([$delID]);
        // Then delete the project itself
        $stmtDel = $pdo->prepare("DELETE FROM tblproject WHERE projectID = ?");
        $stmtDel->execute([$delID]);
        $pdo->commit();
        header("Location: index.php");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $deleteProjectError = "Error deleting project: " . $e->getMessage();
    }
}

/* ---------------------------
   Add Project Processing
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['addProject'])) {
    $prNumber = trim($_POST['prNumber']);
    $projectDetails = trim($_POST['projectDetails']);
    $userID = $_SESSION['userID'];
    if (empty($prNumber) || empty($projectDetails)) {
        $projectError = "Please fill in all required fields.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO tblproject (prNumber, projectDetails, userID) VALUES (?, ?, ?)");
            $stmt->execute([$prNumber, $projectDetails, $userID]);
            $newProjectID = $pdo->lastInsertId();
            // Insert stages for the new project (set createdAt for 'Purchase Request')
            foreach ($stagesOrder as $stageName => $shortForm) {
                $insertCreatedAt = ($stageName === 'Purchase Request') ? date("Y-m-d H:i:s") : null;
                $stmtInsertStage = $pdo->prepare("INSERT INTO tblproject_stages (projectID, stageName, createdAt) VALUES (?, ?, ?)");
                $stmtInsertStage->execute([$newProjectID, $stageName, $insertCreatedAt]);
            }
            $pdo->commit();
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $projectError = "Error adding project: " . $e->getMessage();
        }
    }
}

/* ---------------------------
   Retrieve Projects (with optional search)
------------------------------ */
$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

// MODIFIED SQL QUERY TO FETCH 'Notice to Proceed' status and the first unsubmitted stage
$sql = "SELECT p.*, u.firstname, u.lastname,
        (SELECT isSubmitted FROM tblproject_stages WHERE projectID = p.projectID AND stageName = 'Notice to Proceed') AS notice_to_proceed_submitted,
        (SELECT s.stageName FROM tblproject_stages s WHERE s.projectID = p.projectID AND s.isSubmitted = 0
            ORDER BY FIELD(s.stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed') ASC
            LIMIT 1) AS first_unsubmitted_stage
        FROM tblproject p
        JOIN tbluser u ON p.userID = u.userID";

if ($search !== "") {
    $sql .= " WHERE p.projectDetails LIKE ? OR p.prNumber LIKE ?";
}
$sql .= " ORDER BY COALESCE(p.editedAt, p.createdAt) DESC";
$stmt = $pdo->prepare($sql);
if ($search !== "") {
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    $stmt->execute();
}
$projects = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard-DepEd BAC Tracking System</title>
  <link rel="stylesheet" href="assets/css/home.css">
    <link rel="stylesheet" href="https://www.w3schools.com/w3css/5/w3.css">
    <style>
        /* Modal styling for Add Project Modal */
        .modal {
            display: none;
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
            margin: 10% auto;
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
        .close:hover { color: black; }
        form label {
            display: block;
            margin-top: 10px;
        }
        form input, form textarea {
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

        /* Table styling */
        .dashboard-table {
            width: 100%;
            border-collapse: collapse;
        }
        .dashboard-table thead tr {
            background-color: #c62828;
            color: white;
            text-align: center;
        }
        .dashboard-table th,
        .dashboard-table td {
            padding: 12px 20px;
            border: 1px solid #eee;
        }
        .dashboard-table td {
            text-align: center;
            vertical-align: middle;
        }

        /* Action Button Styles */
        .edit-project-btn, .delete-btn {
            width: 30px;
            height: 30px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 0;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            color: inherit;
            background-color: transparent;
        }
        .edit-project-btn { background-color: #0D47A1; color: white; }
        .delete-btn { background-color: #C62828; color: white; }
        .back-btn {
            display: inline-block;
            background-color: #0d47a1;
            color: #fff;
            padding: 8px 12px;
            text-decoration: none;
            border-radius: 4px;
            margin: 10px;
        }

        /* Responsive styling */
        @media (max-width: 900px) {
            .dashboard-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
        /* Scrollbar styling for the table */
        .dashboard-table::-webkit-scrollbar {
            height: 8px;
        }
        .dashboard-table::-webkit-scrollbar-thumb {
            background: #c62828;
            border-radius: 4px;
        }

        /* Header styling */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
        }
        .header a {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: inherit;
        }
        .header-logo {
            width: 50px;
            height: auto;
        }
        .header-text {
            margin-left: 10px;
        }
        .title-left, .title-right {
            font-size: 14px;
            line-height: 1.2;
        }

        /* User-menu dropdown */
        .user-menu {
            display: flex;
            align-items: center;
            position: relative;
        }
        .user-name {
            margin-right: 10px;
        }
        .user-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: #fff;
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
            border-radius: 6px;
            z-index: 100;

        }
        .dropdown-content a {
            flex-direction: row;
            display: block;
            padding: 8px 20px;
            text-decoration: none;
            color: #333;
            white-space: nowrap;
        }
        .dropdown.open .dropdown-content {
            display: block;

        }

        /* Top Bar Container: Align Search Bar & Add Button */
        .table-top-bar {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            margin-top: 150px;
            padding: 0 10px;
        }
        .dashboard-search-bar {
            width: 80%;
            max-width: 400px;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .search-and-add { /* Contains "Add PR" button only in Dashboard.html */
            display: flex;
            justify-content: flex-start; /* Aligns "Add PR" button to the left */
            align-items: center;
            margin-bottom: 20px;
            margin-top: 20px;
            margin-left: 10px; /* Aligns it to the left of the search bar */
        }

        .add-pr-button {
            background-color: #0d47a1;
            color: #fff;
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 15px;
            margin-left: 5vh;
            display: flex;
        }
        .add-pr-button img {
            vertical-align: middle;
            margin-right: 5px;
        }

        /* "No results" message */
        #noResults {
            text-align: center;
            font-weight: bold;
            margin-top: 20px;
        }

@media (max-width: 500px) {
  .dashboard-table thead {
    display: none;
  }

  .dashboard-table,
  .dashboard-table tbody,
  .dashboard-table tr,
  .dashboard-table td {

    display: block;
    width: 100%;
  }

  .dashboard-table tr {
    margin-bottom: 15px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background-color: #fff;
    padding: 0;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);

  }

  .dashboard-table td {
    text-align: left;
    padding: 10px 12px;
    border: none;
    border-bottom: 1px solid #eee;
    position: relative;
    background-color: #fff;
    color: #000;
  }

  .dashboard-table td::before {
    content: attr(data-label);
    font-weight: bold;
    display: block;
    margin-bottom: 4px;
    font-size: 14px;
    color: #444;
  }

  .dashboard-table td:last-child {
    border-bottom: none;
  }

  /* Entire PR Number cell gets red background and white text */
  .dashboard-table .pr-number-cell {
    background-color: #c62828;
    color: white;
    border-top-left-radius: 6px;
    border-top-right-radius: 6px;
  }

  .dashboard-table .pr-number-cell::before {
    color: white;
  }
  .dashboard-table .pr-number-cell {
  background-color: #c62828 !important;
  color: white !important;
  border-top-left-radius: 6px;
  border-top-right-radius: 6px;
}

.dashboard-table .pr-number-cell::before {
  color: white !important;
}

}


    </style>
</head>
<body>
    <div class="header">
        <a href="index.php">
            <img src="assets/images/DEPED-LAOAG_SEAL_Glow.png" alt="DepEd Logo" class="header-logo">
            <div class="header-text">
                <div class="title-left">
                    SCHOOLS DIVISION OF LAOAG CITY<br>DEPARTMENT OF EDUCATION
                </div>
                <?php if (isset($showTitleRight) && $showTitleRight): ?>
                <div class="title-right">
                    Bids and Awards Committee Tracking System
                </div>
                <?php endif; ?>
            </div>
        </a>
        <div class="user-menu">
            <span class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <div class="dropdown" id="profileDropdown">
                <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png" alt="User Icon" class="user-icon" id="profileIcon">
                <span id="dropdownArrow" style="cursor:pointer; margin-left:4px;"></span>
        <div class="dropdown-content">
            <?php if ($_SESSION['admin'] == 1): ?>
                <a href="create_account.php">Create Account</a>
                <a href="manage_accounts.php">Manage Accounts</a>
            <?php else: ?>
                <a href="edit_account.php">Edit Account</a>
            <?php endif; ?>
            <a href="logout.php" id="logoutBtn">Log out</a>
        </div>

            </div>
        </div>
    </div>

    <div class="table-top-bar">
        <input type="text" id="searchInput" class="dashboard-search-bar" placeholder="Search by PR Number or Project Details...">
        <div class="search-and-add" id="addProjectSection">

        </div>
    </div>
        <button class="add-pr-button" id="showAddProjectForm">
                <img src="assets/images/Add_Button.png" alt="Add" class="add-pr-icon">
                Add Project
        </button>
    <div>

    </div>
    <?php
        if (isset($projectError)) {
            echo "<p style='color:red; text-align:center;'>" . htmlspecialchars($projectError) . "</p>";
        }
        if (isset($deleteProjectError)) {
            echo "<p style='color:red; text-align:center;'>" . htmlspecialchars($deleteProjectError) . "</p>";
        }
    ?>

    <div class="container" style="padding: 0    2.5vw;">
  <table class="w3-table-all w3-hoverable dashboard-table">
            <thead>
                <tr class="w3-red">
                    <th style="width:100px;">PR Number</th>
                    <th style="width:100px;">Project Details</th>
                    <th style="width:100px;">Created By</th>
                    <th style="width:120px;">Date Created</th>
                    <th style="width:120px;">Date Edited</th>
                    <th style="width:100px;">Status</th>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
  <?php foreach ($projects as $project): ?>
  <tr>
    <td data-label="PR Number" class="pr-number-cell">
        <?php echo htmlspecialchars($project['prNumber']); ?>
    </td>
    <td data-label="Project Details"><?php echo htmlspecialchars($project['projectDetails']); ?></td>
    <td data-label="Created By">
      <?php
        if (!empty($project['firstname']) && !empty($project['lastname'])) {
          echo htmlspecialchars(substr($project['firstname'], 0, 1) . ". " . $project['lastname']);
        } else {
          echo "N/A";
        }
      ?>
    </td>
    <td data-label="Date Created"><?php echo date("m-d-Y", strtotime($project['createdAt'])); ?></td>
    <td data-label="Date Edited"><?php echo date("m-d-Y", strtotime($project['editedAt'])); ?></td>
    <td data-label="Status">
      <?php
        // Determine project status: 'Finished' if 'Notice to Proceed' is submitted,
        // otherwise display the first unsubmitted stage.
        if ($project['notice_to_proceed_submitted'] == 1) {
            echo 'Finished';
        } else {
            // Display the first unsubmitted stage, or 'All Stages Submitted' if none are unsubmitted (shouldn't happen if NtP isn't finished)
            echo htmlspecialchars($project['first_unsubmitted_stage'] ?? 'No Stages Started');
        }
      ?>
    </td>
    <td data-label="Actions">
      <a href="edit_project.php?projectID=<?php echo $project['projectID']; ?>" class="edit-project-btn" title="Edit Project" style="margin-right: 5px;">
        <img src="assets/images/Edit_icon.png" alt="Edit Project" style="width:24px;height:24px;">
      </a>
      <?php if ($_SESSION['admin'] == 1): ?>
      <a href="index.php?deleteProject=<?php echo $project['projectID']; ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this project and all its stages?');" title="Delete Project">
        <img src="assets/images/delete_icon.png" alt="Delete Project" style="width:24px;height:24px;">
      </a>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</tbody>

        </table>
    </div>

    <div id="noResults" style="display:none;">No results</div>

    <div id="addProjectModal" class="modal">
        <div class="modal-content">
            <span class="close" id="addProjectClose">&times;</span>
            <h2>Add Project</h2>
            <form id="addProjectForm" action="index.php" method="post">
                <label for="prNumber">Project Number (PR Number)*</label>
                <input type="text" name="prNumber" id="prNumber" required>
                <label for="projectDetails">Project Details*</label>
                <textarea name="projectDetails" id="projectDetails" rows="4" required></textarea>
                <button type="submit" name="addProject">Add Project</button>
            </form>
        </div>
    </div>

    <script>
        // Add Project Modal logic
        const addProjectModal = document.getElementById('addProjectModal');
        const addProjectClose = document.getElementById('addProjectClose');
        document.getElementById('showAddProjectForm').addEventListener('click', function() {
            addProjectModal.style.display = 'block';
        });
        addProjectClose.addEventListener('click', function() {
            addProjectModal.style.display = 'none';
        });
        window.addEventListener('click', function(event) {
            if (event.target === addProjectModal) {
                addProjectModal.style.display = 'none';
            }
        });
        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape") {
                addProjectModal.style.display = 'none';
            }
        });

        // Button redirections for Create and Manage Accounts.
        if (document.getElementById('goToCreate')) {
            document.getElementById('goToCreate').onclick = function() {
                window.location.href = "create_account.php";
            };
        }
        if (document.getElementById('goToAccounts')) {
            document.getElementById('goToAccounts').onclick = function() {
                window.location.href = "manage_accounts.php";
            };
        }

        // Search functionality for filtering projects.
          document.getElementById("searchInput").addEventListener("keyup", function() {
    let query = this.value.toLowerCase().trim();
    let rows = document.querySelectorAll("table.dashboard-table tbody tr");
    let visibleCount = 0;

    // Determine the appropriate display style based on screen width
    // This will apply 'block' if the media query for mobile is active
    // and 'table-row' otherwise.
    const displayStyle = window.matchMedia("(max-width: 500px)").matches ? "block" : "table-row";

    rows.forEach(row => {
        let prNumber = row.children[0].textContent.toLowerCase();
        let projectDetails = row.children[1].textContent.toLowerCase();
        if (prNumber.includes(query) || projectDetails.includes(query)) {
            row.style.display = displayStyle; // Use the determined display style
            visibleCount++;
        } else {
            row.style.display = "none";
        }
    });
    const noResultsDiv = document.getElementById("noResults");
    noResultsDiv.style.display = (visibleCount === 0) ? "block" : "none";
});



        // Profile dropdown toggle logic
        const profileIcon = document.getElementById('profileIcon');
        const dropdownArrow = document.getElementById('dropdownArrow');
        const profileDropdown = document.getElementById('profileDropdown');
        function toggleDropdown(event) {
            event.stopPropagation();
            profileDropdown.classList.toggle('open');
        }
        profileIcon.addEventListener('click', toggleDropdown);
        dropdownArrow.addEventListener('click', toggleDropdown);
        document.addEventListener('click', function(event) {
            if (!profileDropdown.contains(event.target)) {
                profileDropdown.classList.remove('open');
            }
        });
    </script>
</body>
</html>