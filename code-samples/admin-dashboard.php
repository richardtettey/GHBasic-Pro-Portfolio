<?php
require "../session.php";
require_once '../config/database.php';
require_once '../config/subscription.php';
requireAdmin();


if (!Subscription::checkActiveSubscription($_SESSION['school_id'])) {
    header('Location: subscription.php');
    exit();
}
$pdo = getDBConnection();
$school_id = getCurrentSchoolId();

// Get current school information
$stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();

// Get school type for private school features
$is_private = ($school['school_type'] ?? '') === 'private';
$subscription_active = Subscription::checkActiveSubscription($school_id);
$trial_days_left = 0;

if ($school['subscription_plan'] === 'trial' && $school['subscription_start']) {
    $trial_end = date('Y-m-d', strtotime($school['subscription_start'] . ' + 14 days'));
    $today = date('Y-m-d');
    $trial_days_left = max(0, floor((strtotime($trial_end) - strtotime($today)) / (60 * 60 * 24)));
}

// Get counts WITH school_id filter
$teachers_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM teachers WHERE school_id = ?");
$teachers_stmt->execute([$school_id]);
$teachers = $teachers_stmt->fetch()['count'];

$students_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE school_id = ? AND (is_completed = 0 OR is_completed IS NULL)");
$students_stmt->execute([$school_id]);
$students = $students_stmt->fetch()['count'];

// Subjects are global, so no school_id filter needed
$subjects = $pdo->query("SELECT COUNT(*) as count FROM subjects")->fetch()['count'];

$class_counts = [];
$class_labels = ['Nursery 1', 'Nursery 2', 'KG 1', 'KG 2', 'Basic 1', 'Basic 2', 'Basic 3', 'Basic 4', 'Basic 5', 'Basic 6', 'Basic 7', 'Basic 8', 'Basic 9', 'Graduated'];

$allClassesStmt = $pdo->query("
    SELECT id, class_name, class_code 
    FROM classes 
    ORDER BY 
        CASE class_name
            WHEN 'Nursery 1' THEN 1
            WHEN 'Nursery 2' THEN 2
            WHEN 'Kingdergarten 1' THEN 3
            WHEN 'Kingdergarten  2' THEN 4
            WHEN 'Basic 1' THEN 5
            WHEN 'Basic 2' THEN 6
            WHEN 'Basic 3' THEN 7
            WHEN 'Basic 4' THEN 8
            WHEN 'Basic 5' THEN 9
            WHEN 'Basic 6' THEN 10
            WHEN 'Basic 7' THEN 11
            WHEN 'Basic 8' THEN 12
            WHEN 'Basic 9' THEN 13
            WHEN 'Graduated/Alumni' THEN 14
            ELSE 15
        END
");
$allClasses = $allClassesStmt->fetchAll();

foreach ($allClasses as $class) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as c 
        FROM students s 
        WHERE s.school_id = ? 
        AND s.class_id = ?
        AND (s.is_completed = 0 OR s.is_completed IS NULL)
    ");
    $stmt->execute([$school_id, $class['id']]);
    $class_counts[$class['class_name']] = $stmt->fetch()['c'];
}

// Get teachers overview WITH school_id filter
$stmt = $pdo->prepare("SELECT assigned_classes FROM teachers WHERE school_id = ?");
$stmt->execute([$school_id]);
$allTeachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$primary = 0;
$jhs = 0;

foreach ($allTeachers as $teacher) {
    $classes = json_decode($teacher['assigned_classes'], true);
    
    if (is_array($classes)) {
        $hasPrimary = false;
        $hasJHS = false;
        
        foreach ($classes as $classId) {
            if ($classId == '1' || $classId == '2' || $classId == '3' || 
                $classId == '4' || $classId == '5' || $classId == '6' ||  $classId == '11' || $classId == '12' || $classId == '13'|| $classId == '14') {
                $hasPrimary = true;
            }
            if ($classId == '7' || $classId == '8' || $classId == '9') {
                $hasJHS = true;
            }
        }
        
        if ($hasPrimary) $primary++;
        if ($hasJHS) $jhs++;
    }
}
// Get settings for current term
$settingsStmt = $pdo->prepare("SELECT * FROM settings WHERE school_id = ?");
$settingsStmt->execute([$school_id]);
$settings = $settingsStmt->fetch();
$current_term = $settings['term'] ?? 'Term 1';
$current_year = $settings['academic_year'] ?? date('Y').'-'.(date('Y')+1);

// Today's marks
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM marks WHERE school_id = ? AND DATE(created_at) = CURDATE()");
$stmt->execute([$school_id]);
$today_marks = $stmt->fetch()['count'];

// Section count
$sectionCount = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(class_id, '-', COALESCE(section, 'none'))) as count FROM students WHERE school_id = ? AND (is_completed = 0 OR is_completed IS NULL)");
$sectionCount->execute([$school_id]);
$total_sections = $sectionCount->fetch()['count'];

// Bursar stats (for private schools only)
$today_payments = 0; $term_collection = 0; $total_outstanding = 0;

// Additional bursar stats
$fully_paid_count = 0;
$partial_count = 0;
$unpaid_count = 0;
$total_expected = 0;
if ($is_private) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) as total FROM fee_payments WHERE school_id = ? AND DATE(payment_date) = CURDATE()");
    $stmt->execute([$school_id]);
    $today_payments = $stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) as total FROM fee_payments WHERE school_id = ? AND term = ? AND academic_year = ?");
    $stmt->execute([$school_id, $current_term, $current_year]);
    $term_collection = $stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance), 0) as total FROM student_fee_balances WHERE school_id = ? AND term = ? AND academic_year = ?");
    $stmt->execute([$school_id, $current_term, $current_year]);
    $total_outstanding = $stmt->fetch()['total'];




    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM student_fee_balances WHERE school_id = ? AND term = ? AND academic_year = ? AND status = 'Paid'");
    $stmt->execute([$school_id, $current_term, $current_year]);
    $fully_paid_count = $stmt->fetch()['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM student_fee_balances WHERE school_id = ? AND term = ? AND academic_year = ? AND status = 'Partial'");
    $stmt->execute([$school_id, $current_term, $current_year]);
    $partial_count = $stmt->fetch()['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM student_fee_balances WHERE school_id = ? AND term = ? AND academic_year = ? AND (status = 'Unpaid' OR status IS NULL)");
    $stmt->execute([$school_id, $current_term, $current_year]);
    $unpaid_count = $stmt->fetch()['count'];
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_fees), 0) as total FROM student_fee_balances WHERE school_id = ? AND term = ? AND academic_year = ?");
    $stmt->execute([$school_id, $current_term, $current_year]);
    $total_expected = $stmt->fetch()['total'];
    
    // Today's collection
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount_paid), 0) as total FROM fee_payments WHERE school_id = ? AND DATE(payment_date) = CURDATE()");
    $stmt->execute([$school_id]);
    $today_data = $stmt->fetch();
    $today_collection = $today_data['total'];
    $today_count = $today_data['count'];
    
    // Collection rate
    $collection_rate = $total_expected > 0 ? round(($term_collection / $total_expected) * 100, 1) : 0;
    
    // Arrears total
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(arrears_from_previous), 0) as total FROM student_fee_balances WHERE school_id = ? AND term = ? AND academic_year = ?");
    $stmt->execute([$school_id, $current_term, $current_year]);
    $total_arrears = $stmt->fetch()['total'];
}

// Payment methods breakdown
$payment_methods = [];
if ($is_private) {
    $methodStmt = $pdo->prepare("SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(amount_paid), 0) as total FROM fee_payments WHERE school_id = ? AND term = ? AND academic_year = ? GROUP BY payment_method ORDER BY total DESC");
    $methodStmt->execute([$school_id, $current_term, $current_year]);
    $payment_methods = $methodStmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?= htmlspecialchars($school['school_name'])?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
      <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

</head>
<body>
    <div id="global-loader">
        <div class="spinner"></div>
    </div>
    
    <script src="../assets/chart.min.js"></script>
    
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="dashboard">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="school-logo">
                    <?php if (!empty($school['logo'])): ?>
                        <img src='../uploads/school_logos/<?php echo htmlspecialchars($school['logo']); ?>' alt='School Logo'>
                    <?php else: ?>
                        <img src='../assets/img/logo.png' alt='Default Logo'>
                    <?php endif; ?>
                </div>
                <h2>Admin Panel</h2>
                <small>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></small>
                <small>School Code: <?php echo htmlspecialchars($school['school_code']); ?></small>
                <small style="margin-top: 5px;">
                    <span class="subscription-badge subscription-<?php echo $school['subscription_plan']; ?>">
                        <?php echo ucfirst($school['subscription_plan']); ?> Plan
                    </span>
                </small>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="active">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
                   <a href="subscription.php">
                    <i class="fas fa-crown"></i>
                    My Subscription
                </a>
                <a href="manage_teachers.php">
                    <i class="fas fa-chalkboard-teacher"></i>
                    Manage Teachers
                </a>
                    <a href="manage_students.php">
                    <i class="fas fa-user-graduate"></i>
                    Manage Students
                </a>
                <a href="manage_admins.php">
                    <i class="fas fa-user-cog"></i>
                    Manage Admins
                </a>

                
                <a href="assign_class_teacher.php">
                    <i class="fas fa-user-tie"></i>
                    Assign Class Teacher
                </a>

                <a href="promote_students.php">
                    <i class="fas fa-arrow-up"></i>
                    Promote Student
                </a>
                <a href="generate_reports.php">
                    <i class="fas fa-file-alt"></i>
                    Generate Reports
                </a>
                
                <a href="view_marks.php">
                    <i class="fas fa-eye"></i>
                   View Marks Recorded
                </a>     
                <a href="peformance_chart.php">
                    <i class="fas fa-chart-line"></i>
                    Performance Analysis
                </a>
                          <a href="aggregates.php"><i class="fas fa-chart-line"></i>Mock Aggregate Calculator</a>
                <a href="manage_academic.php">
                    <i class="fas fa-calendar-alt"></i>
                    Manage Academic Year
                </a>
                <a href="settings.php">
                    <i class="fas fa-cog"></i>
                    Settings
                </a>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navigation -->
            <div class="topnav">
                <div class="topnav-content">
                    <div class="topnav-left">
                        <div class="hamburger" id="hamburgerMenu">
                            <div></div>
                            <div></div>
                            <div></div>
                        </div>
                    </div>
                    <div class="user-info">
                        <a href='settings.php'><i class="fas fa-user-circle"></i></a>
                        <span>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Content Area -->
            <div class="content-area">
                <?php if ($school['subscription_plan'] === 'trial' && $trial_days_left <= 7): ?>
                <div class="alert alert-warning">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem;"></i>
                        <div>
                            <strong>Trial Ending Soon!</strong> 
                            Your trial ends in <?php echo $trial_days_left; ?> days. 
                            <a href="subscription.php" style="color: var(--warning); font-weight: 600; text-decoration: underline;">Upgrade now</a> to continue uninterrupted service.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                              <h1 class="school-title">
                    <i class="fas fa-school" style="margin-right: 0.5rem;"></i>
                    <?= htmlspecialchars($school['school_name'])?>
                </h1>
                
                <!-- Quick Stats -->
                <div class="stats-grid">
                    <a href="manage_teachers.php" class="stat-card">
                        <div class="stat-icon green">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Teachers</h3>
                            <p class="stat-number"><?php echo $teachers; ?></p>
                        </div>
                    </a>
                    
                    <a href="manage_students.php" class="stat-card">
                        <div class="stat-icon purple">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Students</h3>
                            <p class="stat-number"><?php echo $students; ?></p>
                        </div>
                    </a>
                    
                    <div class="stat-card">
                        <div class="stat-icon orange">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Subjects</h3>
                            <p class="stat-number"><?php echo $subjects; ?></p>
                        </div>
                    </div>
   
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="fas fa-chalkboard"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Classes</h3>
<p class="stat-number"><?php echo count($allClasses); ?></p>
                        </div>
                    </div>
                </div>
             
                <!-- Charts -->
                <div class="chart-container">
                    <div class="chart-card">
                        <h3>
                            <i class="fas fa-chart-bar"></i>
                            Class Distribution
                        </h3>
                        <canvas id="classesChart"></canvas>
                    </div>
                    
                    <div class="chart-card">
                        <h3>
                            <i class="fas fa-chart-pie"></i>
                            Teachers Distribution
                        </h3>
                        <canvas id="teachersChart"></canvas>
                    </div>
                </div>
           
    
<!-- Bursar Stats (Private Schools Only) -->
<?php if ($is_private): ?>
<h2 style="color: var(--primary); margin-bottom: 1rem; font-size: 1.3rem; display: flex; align-items: center; gap: 0.5rem;" class='finance'>
    <i class="fas fa-money-bill-wave"></i> Financial Overview — <?= $current_term ?> <?= $current_year ?>
</h2>

<div class="stats-grid">
    <!-- Today's Collection -->
    <a href="#" class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="stat-content">
            <h3>Today's Collection</h3>
            <p class="stat-number">GHS <?php echo number_format($today_collection, 2); ?></p>
            <small style="font-size: 0.7rem; color: var(--text-light);"><?php echo $today_count; ?> payment(s)</small>
        </div>
    </a>

    <!-- Term Collection -->
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-content">
            <h3>Term Collection</h3>
            <p class="stat-number">GHS <?php echo number_format($term_collection, 2); ?></p>
        </div>
    </div>

    <!-- Total Expected -->
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-calculator"></i>
        </div>
        <div class="stat-content">
            <h3>Total Expected</h3>
            <p class="stat-number">GHS <?php echo number_format($total_expected, 2); ?></p>
        </div>
    </div>

    <!-- Outstanding Balance -->
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="stat-content">
            <h3>Outstanding Balance</h3>
            <p class="stat-number">GHS <?php echo number_format($total_outstanding, 2); ?></p>
        </div>
    </div>



    <!-- Previous Arrears -->
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <h3>Previous Arrears</h3>
            <p class="stat-number">GHS <?php echo number_format($total_arrears, 2); ?></p>
        </div>
    </div>
</div>

<h3 style="color: var(--secondary); margin-bottom: 1rem; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
    <i class="fas fa-users"></i> Payment Status Breakdown
</h3>

<div class="stats-grid">
    <!-- Fully Paid -->
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-content">
            <h3>Fully Paid</h3>
            <p class="stat-number"><?php echo $fully_paid_count; ?></p>
            <small style="font-size: 0.7rem; color: var(--text-light);">students</small>
        </div>
    </div>

    <!-- Partial Payment -->
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <h3>Partial Payment</h3>
            <p class="stat-number"><?php echo $partial_count; ?></p>
            <small style="font-size: 0.7rem; color: var(--text-light);">students</small>
        </div>
    </div>

    <!-- Unpaid -->
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-content">
            <h3>Unpaid</h3>
            <p class="stat-number"><?php echo $unpaid_count; ?></p>
            <small style="font-size: 0.7rem; color: var(--text-light);">students</small>
        </div>
    </div>

    <!-- Payment Methods Breakdown -->
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-credit-card"></i>
        </div>
        <div class="stat-content">
            <h3>Payment Methods</h3>
            <p class="stat-number" style="font-size: 0.9rem;">
                <?php 
                $methodStmt = $pdo->prepare("SELECT payment_method, COUNT(*) as cnt FROM fee_payments WHERE school_id = ? AND term = ? AND academic_year = ? GROUP BY payment_method");
                $methodStmt->execute([$school_id, $current_term, $current_year]);
                $methods = $methodStmt->fetchAll();
                foreach ($methods as $m) {
                    echo htmlspecialchars($m['payment_method']) . ': ' . $m['cnt'] . '<br>';
                }
                ?>
            </p>
        </div>
    </div>
</div>
<?php endif; ?>


    <script nonce="<?= $GLOBALS['csp_nonce']?>">
        // Loader functionality
        window.addEventListener("load", function () {
            const loader = document.getElementById("global-loader");
            if (loader) {
                loader.style.opacity = "0";
                loader.style.pointerEvents = "none";
                setTimeout(() => loader.style.display = "none", 300);
            }
        });

        document.addEventListener("submit", function () {
            document.getElementById("global-loader").style.display = "flex";
        });

   

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Classes Chart
            const classesCtx = document.getElementById('classesChart').getContext('2d');
            new Chart(classesCtx, {
                type: 'bar',
                data: {
                labels: <?php echo json_encode(array_keys($class_counts)); ?>,
datasets: [{
    label: 'Students',
    data: <?php echo json_encode(array_values($class_counts)); ?>,
                        
                        backgroundColor: [
                            '#059669', '#10b981', '#34d399', '#6ee7b7', 
                            '#a7f3d0', '#d1fae5', '#059669', '#10b981', '#34d399'
                        ],
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 },
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
            
            // Teachers Chart
            const teachersCtx = document.getElementById('teachersChart').getContext('2d');
            new Chart(teachersCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Primary Teachers', 'JHS Teachers'],
                    datasets: [{
                        data: [
                            <?php echo $primary; ?>,
                            <?php echo $jhs; ?>,

                        ],
                        backgroundColor: ['#059669', '#3b82f6', '#f59e0b'],
                        borderWidth: 0,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    label += context.parsed + ' teacher(s)';
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
    
    <script src="../assets/js/main.js"></script>

</body>
</html>