<?php
session_start();
require 'db-config.php';

// Set admin password (same as in JavaScript)
define('ADMIN_PASSWORD', 'admin123');

// Handle login request
if (isset($_POST['password']) && $_POST['password'] === ADMIN_PASSWORD) {
    $_SESSION['admin_logged_in'] = true;
}

// Authentication check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.html');
    exit;
}

// Handle back button (session destroy)
if (isset($_GET['back'])) {
    // Clear all session variables
    $_SESSION = array();
    // Destroy the session
    session_destroy();
    // Redirect to the login page
    header('Location: index.html');
    exit;
}

// Handle marking as read/unread
if (isset($_POST['toggle_published'])) {
    try {
        $pdo = new PDO(DSN, DB_USER, DB_PASS, PDO_OPTIONS);
        $ids = $_POST['selected_ids'] ?? [];
        $publish_status = $_POST['publish_status'];
        
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // Update the is_published field
            $stmt = $pdo->prepare("UPDATE shoutouts SET is_published = ? WHERE id IN ($placeholders)");
            $params = array_merge([$publish_status], $ids);
            $stmt->execute($params);
            
            header('Location: admin.php?updated=1');
            exit;
        }
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

try {
    $pdo = new PDO(DSN, DB_USER, DB_PASS, PDO_OPTIONS);
    
    // Add is_published column if it doesn't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM shoutouts LIKE 'is_published'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE shoutouts ADD COLUMN is_published TINYINT(1) DEFAULT 0");
    }
    
    // Delete action
    if (isset($_GET['delete'])) {
        $stmt = $pdo->prepare("DELETE FROM shoutouts WHERE id = ?");
        $stmt->execute([$_GET['delete']]);
        header('Location: admin.php?deleted=1');
        exit;
    }

    // Filters and Pagination
    $search = $_GET['search'] ?? '';
    $dateFilter = $_GET['date'] ?? '';
    $page = (int)($_GET['page'] ?? 1);
    $limit = 5; // Changed from 15 to 5 rows per page
    $offset = ($page - 1) * $limit;

    // Build SQL queries
    $baseSql = "FROM shoutouts WHERE 1=1";
    $params = [];
    
    // Search filter
    if (!empty($search)) {
        $baseSql .= " AND (sender_name LIKE ? OR recipient_name LIKE ? OR message LIKE ?)";
        array_push($params, "%$search%", "%$search%", "%$search%");
    }

    // Date filter
    if (!empty($dateFilter)) {
        $baseSql .= " AND DATE(created_at) = ?";
        $params[] = $dateFilter;
    }

    // Main query
    $sql = "SELECT * $baseSql ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $shoutouts = $stmt->fetchAll();

    // Count query
    $countSql = "SELECT COUNT(*) $baseSql";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalItems = $countStmt->fetchColumn();
    $totalPages = ceil($totalItems / $limit);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Email domain validation function
function isValidEmailDomain($email) {
    $validDomains = ['@vdartinc.com', '@dimiour.io', '@trustpeople.com'];
    foreach ($validDomains as $domain) {
        if (strpos($email, $domain) !== false) {
            return true;
        }
    }
    return false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Shoutouts</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2a61b0;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --read-color:rgb(168, 170, 172); /* Gray color for read items */
            --unread-color: #000000; /* Black color for unread items */
        }

        * {
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            margin: 0;
            padding: 2rem;
            background-color: #f9f9f9;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .admin-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-back {
            background-color: #6c757d;
            color: white;
        }

        .btn-publish {
            background-color: var(--success-color);
            color: white;
            margin-bottom: 1rem;
            margin-right: 0.5rem;
        }

        .bulk-actions {
            display: none; /* Hidden by default */
            margin-bottom: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: var(--shadow);
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background-color: var(--primary-color);
            color: white;
            position: static; /* sticky */
            top: 0;
        }

        tr:hover {
            background-color: #f5f5f5;
        }

        /* Read/Unread styling */
        tr.read {
            color: var(--read-color);
        }

        tr.unread {
            color: var(--unread-color);
            font-weight: 500;
        }

        .checkbox-cell {
            width: 50px;
            text-align: center;
        }

        .row-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .filter-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        input[type="search"],
        input[type="date"] {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 100%;
        }

        .message-content {
            position: relative;
            max-height: 4.5em; /* Approximately 2 rows (using line-height 1.5) */
            overflow: hidden; /* Hide overflow initially */
            transition: max-height 0.3s ease;
            line-height: 1.5; /* Standardize line height */
            padding-right: 10px; /* Add padding for scrollbar */
            cursor: pointer; /* Show it's clickable */
        }

        .message-content.expanded {
            max-height: 11.25em; /* Show 5 rows when expanded */
            overflow-y: auto; /* Enable scrolling when expanded */
        }

        .message-content.has-more::after {
            content: "...";
            position: absolute;
            bottom: 0;
            right: 0;
            background-color: white;
            padding: 0 4px;
            cursor: pointer;
            color: var(--primary-color);
            font-weight: bold;
        }
        /* Hide the dots when the message is expanded */
        .message-content.expanded.has-more::after {
            display: none;
        }

        /* Customize scrollbar appearance */
        .message-content::-webkit-scrollbar {
            width: 6px;
        }

        .message-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .message-content::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 3px;
        }

        .message-content::-webkit-scrollbar-thumb:hover {
            background: #1a428a;
        }

        .image-caption {
            color: var(--primary-color);
            cursor: pointer;
            text-decoration: underline;
            transition: color 0.3s ease;
        }

        .image-caption:hover {
            color: #1a428a;
        }

        /* Enhanced Modal Styling */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        /* Add these updated modal styles to your existing CSS */
        
        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            max-width: 90%;
            max-height: 90vh;
            text-align: center;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: modalFadeIn 0.3s ease;
            overflow-y: auto; /* Changed from 'hidden' to 'auto' to allow scrolling */
            display: flex;
            flex-direction: column;
        }

        .modal-image-container {
            position: relative;
            margin-bottom: 1.5rem;
            overflow: hidden;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: flex-start; /* Align to top */
        }

        #modalImage {
            max-width: 100%;
            max-height: 60vh; /* Reduced from 70vh to leave space for the download button */
            display: block;
            margin: 0 auto;
            transition: transform 0.3s ease;
            object-fit: contain;
        }

        .modal-actions {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 1rem;
            /* Make sure this is always visible */
            position: sticky;
            bottom: 0;
            background: white;
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }

        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background-color: rgba(0,0,0,0.5);
            color: white;
            border: none;
            border-radius: 50%;
            width: 2.5rem;
            height: 2.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.2s ease;
            z-index: 10;
        }

        .modal-close:hover {
            background-color: rgba(220,53,69,0.8);
        }

        .download-btn {
            background-color: var(--primary-color);
            color: white;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-weight: 500;
        }

        .download-btn:hover {
            background-color: #1a428a;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .pagination {
            margin-top: 2rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .pagination a {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-decoration: none;
            color: var(--primary-color);
            transition: all 0.3s ease;
        }

        .pagination a:hover:not(.active) {
            background-color: #f0f0f0;
        }

        .pagination a.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* Alert messages */
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-danger {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        /* New styles for serial number and date display */
        .serial-number {
            font-weight: bold;
            text-align: center;
        }
        
        .date-display {
            font-size: 0.8rem;
            color: #6c757d;
            background-color: #f0f0f0;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }
        
        .created-at-time {
            font-size: 0.75rem;
            color: #9a9a9a;
            display: block;
            margin-top: 2px;
        }

        /* Publish Confirmation Modal */
        #publishModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            justify-content: center;
            align-items: center;
            z-index: 1001;
        }

        .publish-modal-content {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }

        .publish-modal-actions {
            margin-top: 1.5rem;
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .btn-yes {
            background-color: var(--success-color);
            color: white;
        }

        .btn-no {
            background-color: var(--danger-color);
            color: white;
        }

        /* Select all checkbox */
        .select-all-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        #selectAll {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Responsive styles */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .admin-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-section {
                grid-template-columns: 1fr;
            }

            table, thead, tbody, th, td, tr { 
                display: block; 
            }
            
            thead tr { 
                position: absolute;
                top: -9999px;
                left: -9999px;
            }
            
            tr { 
                margin-bottom: 1rem;
                box-shadow: var(--shadow);
                border-radius: 8px;
                overflow: hidden;
                background-color: white;
            }
            
            td {
                border: none;
                position: relative;
                padding-left: 50%;
                text-align: right;
            }

            td.checkbox-cell {
                padding-left: 1rem;
                text-align: left;
            }
            
            td::before {
                content: attr(data-label);
                position: absolute;
                left: 1rem;
                font-weight: 500;
                color: var(--primary-color);
                text-align: left;
            }

            .message-content, .message-content.expanded {
                text-align: left;
            }
            
            .serial-number {
                text-align: right;
                padding-left: 50%;
            }
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <h1>Shoutout Submissions (<?= $totalItems ?>)</h1>
        <div class="admin-actions">
            <a href="admin.php?back=1" class="btn btn-back">
                <i class="fas fa-sign-out-alt"></i> Back to Login
            </a>
            <a href="admin.php" class="btn btn-primary">
                <i class="fas fa-sync-alt"></i> Refresh
            </a>
        </div>
    </div>

    <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> Shoutout successfully deleted.
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> Shoutout status successfully updated.
    </div>
    <?php endif; ?>

    <form method="get" class="filter-section">
        <div class="filter-group">
            <input type="search" name="search" placeholder="Search..." 
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        
        <div class="filter-group">
            <input type="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>">
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Apply Filters
        </button>
    </form>

    <div class="select-all-container">
        <input type="checkbox" id="selectAll">
        <label for="selectAll">Select All</label>
    </div>

    <div class="bulk-actions" id="bulkActions">
        <button id="markPublishedBtn" class="btn btn-publish">
            <i class="fas fa-check-circle"></i> Mark as Published
        </button>
    </div>

    <form id="bulkForm" method="post" action="admin.php">
        <input type="hidden" name="toggle_published" value="0">
        <input type="hidden" name="publish_status" id="publishStatus" value="0">
        <table>
            <thead>
                <tr>
                    <th class="checkbox-cell">
                        <i class="fas fa-check-square"></i>
                    </th>
                    <th>SN</th>
                    <th>Sender</th>
                    <th>Recipient</th>
                    <th>Message</th>
                    <th>Photo</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Calculate the starting serial number based on pagination
                $startingSerialNumber = ($page - 1) * $limit + 1;
                foreach ($shoutouts as $index => $shoutout): 
                ?>
                <?php
                    // Validate email domains
                    $senderEmailValid = isValidEmailDomain($shoutout['sender_email']);
                    $recipientEmailValid = isValidEmailDomain($shoutout['recipient_email']);
                    // Calculate serial number
                    $serialNumber = $startingSerialNumber + $index;
                    // Determine if row is read (published) or unread
                    $rowClass = isset($shoutout['is_published']) && $shoutout['is_published'] ? 'read' : 'unread';
                ?>
                <tr class="<?= $rowClass ?>">
                    <td class="checkbox-cell">
                        <input type="checkbox" class="row-checkbox" name="selected_ids[]" value="<?= $shoutout['id'] ?>">
                    </td>
                    <td data-label="SN" class="serial-number">
                        <?= $serialNumber ?>
                    </td>
                    <td data-label="Sender" class="<?= $senderEmailValid ? '' : 'invalid-email' ?>">
                        <?= htmlspecialchars($shoutout['sender_name']) ?><br>
                        <small><?= htmlspecialchars($shoutout['sender_email']) ?></small><br>
                        <span class="created-at-time"><?= date('M j, Y H:i', strtotime($shoutout['created_at'])) ?></span>
                    </td>
                    <td data-label="Recipient" class="<?= $recipientEmailValid ? '' : 'invalid-email' ?>">
                        <?= htmlspecialchars($shoutout['recipient_name']) ?><br>
                        <small><?= htmlspecialchars($shoutout['recipient_email']) ?></small>
                    </td>
                    <td data-label="Message">
                        <div class="message-content" id="message-<?= $shoutout['id'] ?>" data-full-text="<?= htmlspecialchars($shoutout['message']) ?>">
                            <?= nl2br(htmlspecialchars($shoutout['message'])) ?>
                        </div>
                    </td>
                    <td data-label="Photo">
                        <?php if ($shoutout['photo_path']): ?>
                        <span class="image-caption" 
                              onclick="showImage('<?= htmlspecialchars($shoutout['photo_path']) ?>', '<?= htmlspecialchars($shoutout['recipient_name']) ?>')">
                            View
                        </span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions">
                        <button type="button" onclick="confirmDelete(<?= $shoutout['id'] ?>)" 
                                class="btn btn-danger">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </form>

    <!-- Pagination Section -->
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                &lt;
            </a>
        <?php endif; ?>

        <?php 
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
               class="<?= $page === $i ? 'active' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                &gt;
            </a>
        <?php endif; ?>
    </div>

    <!-- Updated Image Preview Modal Structure -->
    <div id="imageModal" class="modal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
            <h3 id="modalTitle"></h3>
            <div class="modal-image-container">
                <img id="modalImage" src="" alt="Preview">
            </div>
            <div class="modal-actions">
                <a id="downloadLink" href="#" download class="download-btn">
                    <i class="fas fa-download"></i> Download Image
                </a>
            </div>
        </div>
    </div>

    <!-- Publish Confirmation Modal -->
    <div id="publishModal" class="modal">
        <div class="publish-modal-content">
            <h3>Is the shoutout published?</h3>
            <div class="publish-modal-actions">
                <button type="button" class="btn btn-yes" onclick="confirmPublish(true)">Yes</button>
                <button type="button" class="btn btn-no" onclick="confirmPublish(false)">No</button>
            </div>
        </div>
    </div>

    <script>
        // Process message content for all messages
        document.addEventListener('DOMContentLoaded', function() {
            // Process all message contents
            const messageElements = document.querySelectorAll('.message-content');
            
            messageElements.forEach(function(element) {
                // Force a reflow to ensure scrollHeight is accurate
                void element.offsetHeight;
                
                // Get line height and element height
                const lineHeight = parseInt(getComputedStyle(element).lineHeight) || 24; // Fallback to 24px if can't get lineHeight
                const height = element.scrollHeight;
                
                // Check if text exceeds 2 lines
                if (height > lineHeight * 2) {
                    // Add "has-more" indicator for expandable messages
                    element.classList.add('has-more');
                    
                    // Add click event for expanding/collapsing
                    element.addEventListener('click', function() {
                        // Toggle expanded class
                        this.classList.toggle('expanded');
                        
                        // If we're collapsing, remove the "has-more" class temporarily so the ellipsis doesn't show
                        // while we're animating the collapse, then add it back after
                        if (this.classList.contains('expanded')) {
                            this.classList.remove('has-more');
                            setTimeout(() => {
                                this.classList.add('has-more');
                            }, 300); // match transition time
                        } else {
                            // If no longer expanded, scroll back to top
                            this.scrollTop = 0;
                        }
                    });
                }
            });

            // Setup checkbox functionality
            setupCheckboxes();
            
            // Apply read/unread styles to parent rows
            applyReadUnreadStyles();
        });

        // Enhanced Image Modal Functions
        function showImage(src, name) {
            document.getElementById('modalTitle').textContent = name + "'s Photo";
            
            // Reset modal to top before showing new image
            const modalContent = document.querySelector('.modal-content');
            modalContent.scrollTop = 0;
            
            // Set image source
            const modalImage = document.getElementById('modalImage');
            modalImage.src = src;
            
            // Set download link
            document.getElementById('downloadLink').href = src;
            
            // Display modal
            document.getElementById('imageModal').style.display = 'flex';
            
            // Add animation class
            modalContent.classList.add('animate');
        }

        function closeModal() {
            document.getElementById('imageModal').style.display = 'none';
            document.getElementById('publishModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal();
            }
        }

        // Delete Confirmation
        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this entry?')) {
                window.location.href = `admin.php?delete=${id}`;
            }
        }
        
        // Apply read/unread styles to rows based on class
        function applyReadUnreadStyles() {
            const readRows = document.querySelectorAll('tr.read');
            const unreadRows = document.querySelectorAll('tr.unread');
            
            // Apply styles to read rows (gray)
            readRows.forEach(row => {
                row.style.color = 'var(--read-color)';
            });
            
            // Apply styles to unread rows (black)
            unreadRows.forEach(row => {
                row.style.color = 'var(--unread-color)';
                row.style.fontWeight = '500';
            });
        }

        // Setup checkbox functionality
        function setupCheckboxes() {
            const selectAllCheckbox = document.getElementById('selectAll');
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            const bulkActions = document.getElementById('bulkActions');
            const markPublishedBtn = document.getElementById('markPublishedBtn');
            
            // Function to check if any checkboxes are selected
            function updateBulkActionsVisibility() {
                const anyChecked = Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
                bulkActions.style.display = anyChecked ? 'block' : 'none';
            }
            
            // Select all checkbox functionality
            selectAllCheckbox.addEventListener('change', function() {
                rowCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                updateBulkActionsVisibility();
            });
            
            // Individual checkbox functionality
            rowCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    // If any checkbox is unchecked, uncheck "select all"
                    if (!this.checked) {
                        selectAllCheckbox.checked = false;
                    } 
                    // If all checkboxes are checked, check "select all"
                    else if (Array.from(rowCheckboxes).every(cb => cb.checked)) {
                        selectAllCheckbox.checked = true;
                    }
                    
                    updateBulkActionsVisibility();
                });
            });
            
            // Initial check
            updateBulkActionsVisibility();
            
            // Publish button click handler
            markPublishedBtn.addEventListener('click', function() {
                const checkedCount = Array.from(rowCheckboxes).filter(cb => cb.checked).length;
                if (checkedCount > 0) {
                    // Show publish confirmation modal
                    document.getElementById('publishModal').style.display = 'flex';
                } else {
                    alert('Please select at least one shoutout to mark as published.');
                }
            });
        }
        
        // Handle publish confirmation
        function confirmPublish(isPublished) {
            document.getElementById('publishStatus').value = isPublished ? '1' : '0';
            document.getElementById('bulkForm').querySelector('input[name="toggle_published"]').value = '1';
            document.getElementById('bulkForm').submit();
        }
    </script>
</body>
</html>