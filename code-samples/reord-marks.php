<?php
require "../session.php";
$_SESSION['csrf'] = bin2hex(random_bytes(32));
require_once '../config/database.php';
require_once '../config/academic_year.php';
require '../vendor/autoload.php';
require_once '../config/subscription.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
requireTeacher();

if ($_SESSION['user_role'] !== 'teacher') {
    header('Location: ../login.php');
    exit();
}


function sanitize($input, $type = 'string') {
    if (is_array($input)) {
        foreach ($input as $key => $value) {
            $input[$key] = sanitize($value, $type);
        }
        return $input;
    }
    
    switch ($type) {
        case 'int':
            return (int) $input;
        case 'float':
            return (float) $input;
        case 'email':
            return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var(trim($input), FILTER_SANITIZE_URL);
        case 'string':
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

$pdo = getDBConnection();
$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];
$message = '';
$subject_id = null;

if (!Subscription::checkActiveSubscription($school_id)) {
    header('Location: subscription_warning.php');
    exit();
}

// Get current school information
$stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();

// Get teacher's subject and assigned classes
$teacher = $pdo->prepare("
    SELECT t.*, s.subject_name, s.id as subject_id 
    FROM teachers t 
    LEFT JOIN subjects s ON t.subject_id = s.id 
    WHERE t.id = ? AND t.school_id =?
");
$teacher->execute([$teacher_id, $school_id]);
$teacher_data = $teacher->fetch();

if (!$teacher_data) {
    die("Teacher not found!");
}

function calculateGrade($score) {
    if ($score >= 80) return ['grade' => '1', 'remark' => 'Excellent'];
    elseif ($score >= 70) return ['grade' => '2', 'remark' => 'Very Good'];
    elseif ($score >= 65) return ['grade' => '3', 'remark' => 'Good'];
    elseif ($score >= 60 ) return ['grade' => '4', 'remark' => 'Good'];
    elseif ($score >= 55) return ['grade' => '5', 'remark' => 'Credit'];
    elseif ($score >= 50) return ['grade' => '6', 'remark' => 'Credit'];
    elseif ($score >= 45) return ['grade' => '7', 'remark' => 'Pass'];
    elseif ($score >= 35) return ['grade' => '8', 'remark' => 'Pass'];
    else return ['grade' => '9', 'remark' => 'Fail'];
}

// Get teacher's subject name
$teacher_subject = $teacher_data['subject_name'] ?? 'No Subject Assigned';

// Check if teacher teaches "All subject" (primary school teacher)
$is_all_subjects = ($teacher_subject === 'All Subject');

// If teacher teaches all subjects, get ALL subjects
if ($is_all_subjects) {
    $all_subjects = $pdo->query("SELECT * FROM subjects WHERE subject_name != 'All Subject' ORDER BY subject_name")->fetchAll();
} else {
    // For regular teachers, just use their assigned subject
    $subject_id = $teacher_data['subject_id'];
}

$assigned_classes = json_decode($teacher_data['assigned_classes'] ?? '[]', true);

// Get all classes
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();

// Filter classes that teacher is assigned to
$allowed_classes = [];
foreach ($classes as $class) {
    if (in_array($class['id'], $assigned_classes)) {
        $allowed_classes[] = $class;
    }
}

// Get selected class from URL or session
$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : ($allowed_classes[0]['id'] ?? null);
$selected_section = $_GET['section'] ?? '';
$students = [];
if ($selected_class_id) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE class_id = ? AND school_id = ? ORDER BY full_name");
    $stmt->execute([$selected_class_id, $school_id]);
    $students = $stmt->fetchAll();
}

// Function to format number
function formatScore($value) {
    if (is_null($value) || $value === '') return '0';
    $floatVal = floatval($value);
    if ($floatVal == 0) return '0';
    if ($floatVal == intval($floatVal)) {
        return (string) intval($floatVal);
    }
    return rtrim(rtrim($floatVal, '0'), '.');
}

// Get sections for this class
$sections = [];
$secStmt = $pdo->prepare("SELECT DISTINCT section FROM students WHERE class_id = ? AND school_id = ? AND section IS NOT NULL AND section != '' ORDER BY section");
$secStmt->execute([$selected_class_id, $school_id]);
$sections = $secStmt->fetchAll(PDO::FETCH_COLUMN);

// Filter students by section if selected
if ($selected_section && $students) {
    $students = array_filter($students, function($s) use ($selected_section) {
        return ($s['section'] ?? '') === $selected_section;
    });
}

if (isset($_GET['export_excel']) && $selected_class_id && isset($_GET['subject_id']) && isset($_GET['term']) && isset($_GET['academic_year'])) {
    $subject_id_export = $_GET['subject_id'];
    $term_export = $_GET['term'];
    $academic_year_export = $_GET['academic_year'];
    $type_export = isset($_GET['type']) ? $_GET['type'] : '';
    
    // Get class name
    $class_stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ?");
    $class_stmt->execute([$selected_class_id]);
    $class_name = $class_stmt->fetchColumn();
    
    // Get subject name
    $subject_stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
    $subject_stmt->execute([$subject_id_export]);
    $subject_name = $subject_stmt->fetchColumn();
    
    // Get existing marks for this class, subject, term, academic year, and exams type
    $marks_stmt = $pdo->prepare("
        SELECT m.*, s.full_name 
        FROM marks m
        JOIN students s ON m.student_id = s.id AND m.school_id = s.school_id
        WHERE s.class_id = ? 
        AND m.subject_id = ?
        AND m.term = ?
        AND m.academic_year = ?
        AND m.exams_type = ?
        AND m.school_id = ?
        ORDER BY s.full_name
    ");
    $marks_stmt->execute([$selected_class_id, $subject_id_export, $term_export, $academic_year_export, $type_export, $school_id]);
    $existing_marks = $marks_stmt->fetchAll();
    
    // Create a map of student_id to marks for quick lookup
    $marks_map = [];
    foreach ($existing_marks as $mark) {
        $marks_map[$mark['student_id']] = $mark;
    }
    
    // Create new Spreadsheet object
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator($_SESSION['full_name'])
        ->setTitle("Marks - $class_name - $subject_name")
        ->setSubject("Student Marks")
        ->setDescription("Marks export for $class_name - $subject_name");
    
    // Title row - include exams type
    $sheet->mergeCells('A1:G1');
    $sheet->setCellValue('A1', "MARKS ENTRY - $class_name - $subject_name - $term_export - $type_export - $academic_year_export");
    $sheet->getStyle('A1')->getFont()->setSize(14)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Header row
    $headers = [
        'A' => 'ID',
        'B' => 'Full Name',
        'C' => 'Test 1',
        'D' => 'Group Work ',
        'E' => 'Test 2',
        'F' => 'Project',
        'G' => 'Exams'
    ];
    
    $row = 2;
    foreach ($headers as $col => $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $sheet->getStyle($col . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E0E0');
    }
    
    // Add data rows
    $row = 3;
    foreach ($students as $student) {
        $sheet->setCellValue('A' . $row, $student['id']);
        $sheet->setCellValue('B' . $row, $student['full_name']);
        
        // Fill existing marks if available
        if (isset($marks_map[$student['id']])) {
            $mark = $marks_map[$student['id']];
            $sheet->setCellValue('C' . $row, $mark['test1'] ?? '');
            $sheet->setCellValue('D' . $row, $mark['project'] ?? '');
            $sheet->setCellValue('E' . $row, $mark['test2'] ?? '');
            $sheet->setCellValue('F' . $row, $mark['exercise'] ?? '');
            $sheet->setCellValue('G' . $row, $mark['exam_score'] ?? '');
        }
        
        $row++;
    }
    
    // Add data validation for marks columns
    $startRow = 3;
    $endRow = $row - 1;
    
    // Test 1 validation (0-30)
    $test1Validation = $sheet->getCell('C' . $startRow)->getDataValidation();
    $test1Validation->setType(DataValidation::TYPE_DECIMAL);
    $test1Validation->setErrorStyle(DataValidation::STYLE_STOP);
    $test1Validation->setAllowBlank(true);
    $test1Validation->setShowInputMessage(true);
    $test1Validation->setShowErrorMessage(true);
    $test1Validation->setErrorTitle('Invalid input');
    $test1Validation->setError('Test 1 must be between 0 and 30');
    $test1Validation->setPromptTitle('Test 1 Score');
    $test1Validation->setPrompt('Enter Test 1 score (0-30)');
    $test1Validation->setFormula1('0');
    $test1Validation->setFormula2('30');
    
    for ($i = $startRow; $i <= $endRow; $i++) {
        $sheet->getCell('C' . $i)->setDataValidation(clone $test1Validation);
    }
    
    // Project validation (0-20)
    $projectValidation = $sheet->getCell('D' . $startRow)->getDataValidation();
    $projectValidation->setType(DataValidation::TYPE_DECIMAL);
    $projectValidation->setErrorStyle(DataValidation::STYLE_STOP);
    $projectValidation->setAllowBlank(true);
    $projectValidation->setShowInputMessage(true);
    $projectValidation->setShowErrorMessage(true);
    $projectValidation->setErrorTitle('Invalid input');
    $projectValidation->setError('Group work must be between 0 and 30');
    $projectValidation->setPromptTitle('Group work Score');
    $projectValidation->setPrompt('Enter Group work score (0-30)');
    $projectValidation->setFormula1('0');
    $projectValidation->setFormula2('30');
    
    for ($i = $startRow; $i <= $endRow; $i++) {
        $sheet->getCell('D' . $i)->setDataValidation(clone $projectValidation);
    }
    
    // Exercise validation (0-20)
    $exerciseValidation = $sheet->getCell('E' . $startRow)->getDataValidation();
    $exerciseValidation->setType(DataValidation::TYPE_DECIMAL);
    $exerciseValidation->setErrorStyle(DataValidation::STYLE_STOP);
    $exerciseValidation->setAllowBlank(true);
    $exerciseValidation->setShowInputMessage(true);
    $exerciseValidation->setShowErrorMessage(true);
    $exerciseValidation->setErrorTitle('Invalid input');
    $exerciseValidation->setError('Test 2 must be between 0 and 30');
    $exerciseValidation->setPromptTitle('Test 2 Score');
    $exerciseValidation->setPrompt('Enter Test 2 score (0-30)');
    $exerciseValidation->setFormula1('0');
    $exerciseValidation->setFormula2('30');
    
    for ($i = $startRow; $i <= $endRow; $i++) {
        $sheet->getCell('E' . $i)->setDataValidation(clone $exerciseValidation);
    }
    
    // Test 2 validation (0-30)
    $test2Validation = $sheet->getCell('F' . $startRow)->getDataValidation();
    $test2Validation->setType(DataValidation::TYPE_DECIMAL);
    $test2Validation->setErrorStyle(DataValidation::STYLE_STOP);
    $test2Validation->setAllowBlank(true);
    $test2Validation->setShowInputMessage(true);
    $test2Validation->setShowErrorMessage(true);
    $test2Validation->setErrorTitle('Invalid input');
    $test2Validation->setError('Project must be between 0 and 30');
    $test2Validation->setPromptTitle('Project Score');
    $test2Validation->setPrompt('Enter project score (0-30)');
    $test2Validation->setFormula1('0');
    $test2Validation->setFormula2('30');
    
    for ($i = $startRow; $i <= $endRow; $i++) {
        $sheet->getCell('F' . $i)->setDataValidation(clone $test2Validation);
    }
    
    // Exams validation (0-100)
    $examsValidation = $sheet->getCell('G' . $startRow)->getDataValidation();
    $examsValidation->setType(DataValidation::TYPE_DECIMAL);
    $examsValidation->setErrorStyle(DataValidation::STYLE_STOP);
    $examsValidation->setAllowBlank(true);
    $examsValidation->setShowInputMessage(true);
    $examsValidation->setShowErrorMessage(true);
    $examsValidation->setErrorTitle('Invalid input');
    $examsValidation->setError('Exams must be between 0 and 100');
    $examsValidation->setPromptTitle('Exams Score');
    $examsValidation->setPrompt('Enter Exams score (0-100)');
    $examsValidation->setFormula1('0');
    $examsValidation->setFormula2('100');
    
    for ($i = $startRow; $i <= $endRow; $i++) {
        $sheet->getCell('G' . $i)->setDataValidation(clone $examsValidation);
    }
    
    // Auto-size columns
    foreach (range('A', 'G') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }
    
    // Add borders to all cells
    $styleArray = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['argb' => 'FF000000'],
            ],
        ],
    ];
    $sheet->getStyle('A1:G' . $endRow)->applyFromArray($styleArray);
    
   
    $sheet->freezePane('A3');
    
   
    $sheet->getProtection()->setSheet(true);
    $sheet->getStyle('C3:G' . $endRow)->getProtection()->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED);
    

    $filename = "Marks_" . str_replace(' ', '_', $class_name) . "_" . 
                str_replace(' ', '_', $subject_name) . "_" . 
                str_replace(' ', '_', $term_export) . "_" . 
                str_replace(' ', '_', $type_export) . "_" . 
                $academic_year_export . ".xlsx";
    
    // Redirect output to a client's web browser
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Cache-Control: max-age=1');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_excel'])) {
    $term = sanitize($_POST['term']);
    $academic_year = sanitize($_POST['academic_year']);
    $class_id = sanitize($_POST['class_id']);
    $type = sanitize($_POST['type']);
    
    if ($is_all_subjects) {
        $subject_id = $_POST['subject_id'];
        if (empty($subject_id)) {
            $_SESSION["msg"] = "Please select a subject!";
            header("Location: record_marks.php?class_id=$class_id");
            exit();
        }
    } else {
        $subject_id = $teacher_data['subject_id'];
    }
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['excel_file']['tmp_name'];
        $fileName = $_FILES['excel_file']['name'];
        
        $allowedExtensions = ['xls', 'xlsx'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if (in_array($fileExtension, $allowedExtensions)) {
            try {
                // Load the uploaded Excel file
                $spreadsheet = IOFactory::load($fileTmpPath);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();
                
                // Remove header rows (first 2 rows)
                array_shift($rows); // Title row
                $headerRow = array_shift($rows); // Header row
                
                $successCount = 0;
                $errorCount = 0;
                $errors = [];
                $importedMarksData = [];
                
                // Start transaction for import
                $pdo->beginTransaction();
                
                foreach ($rows as $rowIndex => $row) {
                    // Skip empty rows
                    if (empty(array_filter($row))) continue;
                    
                    // Parse data based on column positions
                    $student_id = isset($row[0]) ? sanitize($row[0]) : ''; // ID column (Column A)
                    $full_name = isset($row[1]) ? sanitize($row[1]) : ''; // Full Name (Column B)
                    $test1 = isset($row[2]) && $row[2] !== '' ? floatval($row[2]) : 0; // Test 1 (Column C)
                    $project = isset($row[3]) && $row[3] !== '' ? floatval($row[3]) : 0; // Project (Column D)
                    $exercise = isset($row[5]) && $row[5] !== '' ? floatval($row[5]) : 0; // Exercise (Column E)
                    $test2 = isset($row[4]) && $row[4] !== '' ? floatval($row[4]) : 0; // Test 2 (Column F)
                    $exam_score = isset($row[6]) && $row[6] !== '' ? floatval($row[6]) : 0; // Exams (Column G)
                    
                    // Validate student exists
                    if (empty($student_id)) {
                        $errors[] = "Row " . ($rowIndex + 3) . ": Missing student ID";
                        $errorCount++;
                        continue;
                    }
                    
                    // Find student in database
                    $student_stmt = $pdo->prepare("SELECT id FROM students WHERE id = ? AND class_id = ?");
                    $student_stmt->execute([$student_id, $class_id]);
                    
                    if ($student_stmt->rowCount() === 0) {
                        $errors[] = "Row " . ($rowIndex + 3) . ": Student ID $student_id not found in class";
                        $errorCount++;
                        continue;
                    }
                    
                    $student_data = $student_stmt->fetch();
                    $student_db_id = $student_data['id'];
                    
                    // Validate marks ranges
                    if ($test1 < 0 || $test1 > 30) {
                        $errors[] = "Row " . ($rowIndex + 3) . ": Test 1 must be between 0 and 30 (got $test1)";
                        $errorCount++;
                        continue;
                    }
                    if ($project < 0 || $project > 30) {
                        $errors[] = "Row " . ($rowIndex + 3) . ": Project must be between 0 and 30 (got $project)";
                        $errorCount++;
                        continue;
                    }
                    if ($exercise < 0 || $exercise > 30) {
                        $errors[] = "Row " . ($rowIndex + 3) . ": Group work must be between 0 and 30 (got $exercise)";
                        $errorCount++;
                        continue;
                    }
                    if ($test2 < 0 || $test2 > 30) {
                        $errors[] = "Row " . ($rowIndex + 3) . ": Test 2 must be between 0 and 30 (got $test2)";
                        $errorCount++;
                        continue;
                    }
                    if ($exam_score < 0 || $exam_score > 100) {
                        $errors[] = "Row " . ($rowIndex + 3) . ": Exams must be between 0 and 100 (got $exam_score)";
                        $errorCount++;
                        continue;
                    }
                    
                    try {
                        // Calculate scores
                        $class_score = ($test1 + $project + $exercise + $test2);
                        $total_score = $class_score + $exam_score;
                        
                        $grade_data = calculateGrade($exam_score);
                        
                        // Use INSERT with ON DUPLICATE KEY UPDATE
                        $stmt = $pdo->prepare("
                            INSERT INTO marks 
                            (student_id, subject_id, teacher_id, school_id, term, academic_year, exams_type,
                             test1, project, exercise, test2, class_score, exam_score, grade, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                            test1 = VALUES(test1),
                            project = VALUES(project),
                            exercise = VALUES(exercise),
                            test2 = VALUES(test2),
                            class_score = VALUES(class_score),
                            exam_score = VALUES(exam_score),
                            grade = VALUES(grade),
                            teacher_id = VALUES(teacher_id),
                            created_at = NOW()
                        ");
                        $stmt->execute([
                            $student_db_id, $subject_id, $teacher_id, $school_id, $term, $academic_year, $type,
                            $test1, $project, $exercise, $test2,
                            $class_score, $exam_score, $grade_data['grade']
                        ]);
                        
                        // Store for IndexedDB sync
                        $importedMarksData[] = [
                            'studentId' => $student_db_id,
                            'test1' => $test1,
                            'project' => $project,
                            'exercise' => $exercise,
                            'test2' => $test2,
                            'exam_score' => $exam_score
                        ];
                        
                        $successCount++;
                        
                    } catch (PDOException $e) {
                        $errors[] = "Row " . ($rowIndex + 3) . ": Database error - " . $e->getMessage();
                        $errorCount++;
                    }
                }
                
                $pdo->commit();
                
                if ($successCount > 0) {
                    $_SESSION["msg"] = "✅ Successfully imported marks for $successCount student(s)!";
                    $_SESSION['imported_marks_data'] = $importedMarksData;
                    if ($errorCount > 0) {
                        $_SESSION["msg"] .= "<br>⚠️ $errorCount record(s) failed to import.";
                    }
                } else {
                    $_SESSION["msg"] = "❌ No valid data found in the uploaded file.";
                    if (!empty($errors)) {
                        $_SESSION["msg"] .= "<br>Errors:<br>" . implode("<br>", array_slice($errors, 0, 5));
                        if (count($errors) > 5) $_SESSION["msg"] .= "<br>... and " . (count($errors) - 5) . " more";
                    }
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION["msg"] = "❌ Error reading Excel file: " . $e->getMessage();
            }
        } else {
            $_SESSION["msg"] = "❌ Invalid file format. Please upload an Excel file (.xls or .xlsx)";
        }
    } else {
        $_SESSION["msg"] = "❌ Please select a file to upload";
    }
    
    header("Location: record_marks.php?class_id=$class_id&term=" . urlencode($term) . "&academic_year=" . urlencode($academic_year) . "&type=" . urlencode($type) . ($is_all_subjects ? "&subject_id=" . $subject_id : ""));
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_multiple_marks'])) {
    $term = sanitize($_POST['term']);
    $academic_year = sanitize($_POST['academic_year']);
    $class_id = sanitize($_POST['class_id']);
    $type = sanitize($_POST['type']);
    
    if ($is_all_subjects) {
        $subject_id = $_POST['subject_id'];
        if (empty($subject_id)) {
            $_SESSION["msg"] = "Please select a subject!";
            header("Location: record_marks.php?class_id=$class_id");
            exit();
        }
    } else {
        $subject_id = $teacher_data['subject_id'];
    }
    
    $scores = $_POST['scores'];
    $success_count = 0;
    $error_count = 0;
    $processed_students = [];
    $errors = [];
    
    // Log incoming data for debugging
    error_log("Starting marks save process - Total students in form: " . count($scores));
    error_log("Term: $term, Academic Year: $academic_year, Type: $type, Subject ID: $subject_id");
    
    try {
        $pdo->beginTransaction();
        
        // Get all students for this class to track who's missing
        $all_students = $pdo->prepare("SELECT id FROM students WHERE class_id = ? AND school_id = ?");
        $all_students->execute([$class_id, $school_id]);
        $all_student_ids = $all_students->fetchAll(PDO::FETCH_COLUMN);
        
        // If section is selected, filter students
        if ($selected_section) {
            $section_students = $pdo->prepare("SELECT id FROM students WHERE class_id = ? AND school_id = ? AND section = ?");
            $section_students->execute([$class_id, $school_id, $selected_section]);
            $all_student_ids = $section_students->fetchAll(PDO::FETCH_COLUMN);
        }
        
        foreach ($scores as $student_id => $score_data) {
            // FIXED: Use isset() instead of !empty() to allow zero values
            if (isset($score_data['test1']) || 
                isset($score_data['test2']) || 
                isset($score_data['exam_score']) ||
                isset($score_data['project']) ||
                isset($score_data['exercise'])) {
                
                // Get values, default to 0 if not set
                $test1 = isset($score_data['test1']) && $score_data['test1'] !== '' ? floatval($score_data['test1']) : 0;
                $project = isset($score_data['project']) && $score_data['project'] !== '' ? floatval($score_data['project']) : 0;
                $exercise = isset($score_data['exercise']) && $score_data['exercise'] !== '' ? floatval($score_data['exercise']) : 0;
                $test2 = isset($score_data['test2']) && $score_data['test2'] !== '' ? floatval($score_data['test2']) : 0;
                $exam_score = isset($score_data['exam_score']) && $score_data['exam_score'] !== '' ? floatval($score_data['exam_score']) : 0;
                
                // Validate ranges
                $valid = true;
                if ($test1 < 0 || $test1 > 30) {
                    $errors[] = "Student ID $student_id: Test 1 must be between 0 and 30 (got $test1)";
                    $valid = false;
                }
                if ($project < 0 || $project > 30) {
                    $errors[] = "Student ID $student_id: Project must be between 0 and 30 (got $project)";
                    $valid = false;
                }
                if ($exercise < 0 || $exercise > 30) {
                    $errors[] = "Student ID $student_id: Exercise must be between 0 and 30 (got $exercise)";
                    $valid = false;
                }
                if ($test2 < 0 || $test2 > 30) {
                    $errors[] = "Student ID $student_id: Test 2 must be between 0 and 30 (got $test2)";
                    $valid = false;
                }
                if ($exam_score < 0 || $exam_score > 100) {
                    $errors[] = "Student ID $student_id: Exam score must be between 0 and 100 (got $exam_score)";
                    $valid = false;
                }
                
   $class_score = ($test1 + $project + $exercise + $test2);
                  if ($class_score > 100) {
                    $errors[] = "class score cannot be greater than 100";
                    $valid = false;
                }

                if (!$valid) {
                    $error_count++;
                    continue;
                }
                
             
                $grade_data = calculateGrade($exam_score);
                
                try {
                    // FIXED: Use INSERT with ON DUPLICATE KEY UPDATE
                    $stmt = $pdo->prepare("
                        INSERT INTO marks 
                        (student_id, subject_id, teacher_id, school_id, term, academic_year, exams_type,
                         test1, project, exercise, test2, class_score, exam_score, grade, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                        test1 = VALUES(test1),
                        project = VALUES(project),
                        exercise = VALUES(exercise),
                        test2 = VALUES(test2),
                        class_score = VALUES(class_score),
                        exam_score = VALUES(exam_score),
                        grade = VALUES(grade),
                        teacher_id = VALUES(teacher_id),
                        created_at = NOW()
                    ");
                    $stmt->execute([
                        $student_id, $subject_id, $teacher_id, $school_id, $term, $academic_year, $type,
                        $test1, $project, $exercise, $test2,
                        $class_score, $exam_score, $grade_data['grade']
                    ]);
                    
                    $success_count++;
                    $processed_students[] = $student_id;
                    
                } catch (PDOException $e) {
                    $errors[] = "Student ID $student_id: Database error - " . $e->getMessage();
                    $error_count++;
                }
            }
        }
        
        // Check for students who were not processed
        $missing_students = array_diff($all_student_ids, $processed_students);
        if (!empty($missing_students)) {
            error_log("Warning: " . count($missing_students) . " students were not processed: " . implode(', ', $missing_students));
        }
        
        $pdo->commit();
        
        // Build success message with details
        if ($success_count > 0) {
            $subject_name = ($is_all_subjects && isset($all_subjects)) ? 
                (array_column($all_subjects, 'subject_name', 'id')[$subject_id] ?? 'Selected Subject') : 
                $teacher_subject;
            
            $msg = "✅ $success_count student(s) marks recorded for $subject_name!";
            
            if ($error_count > 0) {
                $msg .= "<br>⚠️ $error_count student(s) had errors and were not saved.";
            }
            
            if (!empty($missing_students)) {
                $msg .= "<br>ℹ️ " . count($missing_students) . " students had no data to save.";
            }
            
            $_SESSION["msg"] = $msg;
            
            // Log success
           // error_log("Successfully saved $success_count marks for subject $subject_id, term $term");
        } else {
            $_SESSION["msg"] = "❌ No marks were saved. Please check your input.";
            if (!empty($errors)) {
                $_SESSION["msg"] .= "<br>Errors: " . implode("<br>", array_slice($errors, 0, 5));
            }
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
       // error_log("Transaction failed: " . $e->getMessage());
        $_SESSION["msg"] = "❌ Transaction failed: " . $e->getMessage();
    }
    
    // Redirect with all parameters
    $redirect_url = "record_marks.php?class_id=$class_id&term=" . urlencode($term) . 
                    "&academic_year=" . urlencode($academic_year) . 
                    "&type=" . urlencode($type);
    if ($is_all_subjects) {
        $redirect_url .= "&subject_id=" . $subject_id;
    }
    if ($selected_section) {
        $redirect_url .= "&section=" . urlencode($selected_section);
    }
    
    header("Location: " . $redirect_url);
    exit();
}

// Get current filter values
$current_term = isset($_GET['term']) ? sanitize($_GET['term']) : '';
$current_academic_year = isset($_GET['academic_year']) ? sanitize($_GET['academic_year']) : '';
$current_type = isset($_GET['type']) ? sanitize($_GET['type']) : '';
$current_subject_id = isset($_GET['subject_id']) ? sanitize($_GET['subject_id']) : ($subject_id ?? '');

// Get existing marks for display
$existing_marks = [];
if ($selected_class_id && $current_term && $current_academic_year && $current_type && ($is_all_subjects ? $current_subject_id : true)) {
    $subject_id_for_marks = $is_all_subjects ? $current_subject_id : $subject_id;
    
    // Get student IDs
    $student_ids = array_column($students, 'id');
    if (!empty($student_ids)) {
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $marks_stmt = $pdo->prepare("
            SELECT * FROM marks 
            WHERE student_id IN ($placeholders)
            AND subject_id = ?
            AND term = ?
            AND academic_year = ?
            AND exams_type = ?
        ");
        $params = array_merge($student_ids, [$subject_id_for_marks, $current_term, $current_academic_year, $current_type]);
        $marks_stmt->execute($params);
        $existing_marks_result = $marks_stmt->fetchAll();
        
        foreach ($existing_marks_result as $mark) {
            $existing_marks[$mark['student_id']] = $mark;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#059669">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Teacher Portal">
    <link rel="apple-touch-icon" href="/assets/icons/icon-152x152.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/icon-192x192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Marks - Offline Ready</title>
    <link rel="stylesheet" href="../assets/css/marks.css?v=2.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
</head>
<body>
    
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
                <h2>Teacher Panel</h2>
                <small>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></small>
                <small>Subject: <?php echo htmlspecialchars($teacher_subject); ?></small>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
                <a href="class_teacher_dashboard.php"><i class="fas fa-chalkboard-teacher"></i>Class Teacher Dashboard</a>
                <a href="#" class="active"><i class="fas fa-pen-alt"></i>Record Marks</a>
                <a href="view_marks.php"><i class="fas fa-eye"></i>View Marks</a>
                <a href="peformance_chart.php"><i class="fas fa-chart-line"></i>Performance Analysis</a>
                <a href="settings.php"><i class="fas fa-cog"></i>Manage Account</a>
                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i>Logout</a>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
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
                        <a href="record_class_score.php?class_id=<?php echo $selected_class_id; ?><?php echo $current_term ? '&term='.urlencode($current_term) : ''; ?><?php echo $current_academic_year ? '&academic_year='.urlencode($current_academic_year) : ''; ?><?php echo $current_type ? '&type='.urlencode($current_type) : ''; ?><?php echo $current_subject_id ? '&subject_id='.urlencode($current_subject_id) : ''; ?>" 
                           class="btn" style="background: #3b82f6; color: white; border-radius: 10px; text-decoration: none; font-weight: 600;">
                            <i class="fas fa-percent"></i> Switch to Class Score Entry
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="content-area">
                <?php if(isset($_SESSION["msg"]) && !empty($_SESSION["msg"])): ?>
                <div id="sessionMessage" style="position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; padding: 15px 30px; border-radius: 8px; background: white; box-shadow: 0 4px 12px rgba(0,0,0,0.15); text-align: center; min-width: 300px; max-width: 600px; animation: slideDown 0.3s ease-out;">
                    <?php echo $_SESSION["msg"]; ?>
                </div>
                <script nonce="<?= $GLOBALS['csp_nonce']?>">
                setTimeout(function() {
                    var msg = document.getElementById('sessionMessage');
                    if(msg) {
                        msg.style.opacity = '0';
                        setTimeout(function() { if(msg && msg.parentNode) msg.remove(); }, 500);
                    }
                }, 8000);
                </script>
                <?php unset($_SESSION['msg']); ?>
                <?php endif; ?>
            </div>
            
            <?php if (!$subject_id && !$is_all_subjects): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>No Subject Assigned!</strong> Please contact the administrator to assign you a subject.
            </div>
            <?php elseif (empty($allowed_classes)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>No Classes Assigned!</strong> Please contact the administrator to assign you classes.
            </div>
            <?php else: ?>
            
            <!-- Class Selection -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-layer-group"></i> Select Class
                </div>
                <div class="card-body">
                    <div class="class-buttons" style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php foreach ($allowed_classes as $class): ?>
                        <a href="?class_id=<?php echo $class['id']; ?><?php echo $current_term ? '&term='.urlencode($current_term) : ''; ?><?php echo $current_academic_year ? '&academic_year='.urlencode($current_academic_year) : ''; ?><?php echo $current_type ? '&type='.urlencode($current_type) : ''; ?><?php echo $current_subject_id ? '&subject_id='.urlencode($current_subject_id) : ''; ?>" 
                           class="btn <?php echo ($selected_class_id == $class['id']) ? 'btn-primary' : 'btn-secondary'; ?>" 
                           style="padding: 10px 20px; text-decoration: none; border-radius: 8px;">
                            <i class="fas fa-graduation-cap"></i>
                            <?php echo htmlspecialchars($class['class_name']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($selected_class_id && count($students) > 0): ?>
            
            <!-- Filter Section -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-filter"></i> Filter Options
                </div>
                <div class="card-body">
                    <form method="GET" class="filter-form" id="filterForm">
                        <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div class="form-group">
                                <label for="term"><i class="fas fa-calendar-alt"></i> Term:</label>
                                <select name="term" id="term" required>
                                    <option value="">Select Term</option>
                                    <option value="Term 1" <?= $current_term == 'Term 1' ? 'selected' : '' ?>>Term 1</option>
                                    <option value="Term 2" <?= $current_term == 'Term 2' ? 'selected' : '' ?>>Term 2</option>
                                    <option value="Term 3" <?= $current_term == 'Term 3' ? 'selected' : '' ?>>Term 3</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="type"><i class="fas fa-file-alt"></i> Exams Type:</label>
                                <select name="type" id="type" required>
                                    <option value="">Select Exams Type</option>
                                    <option value="End of term" <?= $current_type == 'End of term' ? 'selected' : '' ?>>End of Term</option>
                                    <option value="Mock 1" <?= $current_type == 'Mock 1' ? 'selected' : '' ?>>Mock 1</option>
                                    <option value="Mock 2" <?= $current_type == 'Mock 2' ? 'selected' : '' ?>>Mock 2</option>
                                    <option value="Mock 3" <?= $current_type == 'Mock 3' ? 'selected' : '' ?>>Mock 3</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="academic_year"><i class="fas fa-calendar-week"></i> Academic Year:</label>
                                <select id="academic_year" name="academic_year" required>
                                    <option value="">Select Academic Year</option>
                                    <?php foreach (AcademicYear::getAllYears() as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $year == $current_academic_year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <?php if ($is_all_subjects): ?>
                            <div class="form-group">
                                <label for="subject_id"><i class="fas fa-book"></i> Subject:</label>
                                <select id="subject_id" name="subject_id">
                                    <option value="">Select Subject</option>
                                    <?php foreach ($all_subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo $subject['id'] == $current_subject_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php else: ?>
                            <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                            <?php endif; ?>
                            
                            <div class="form-group" style="display: flex; align-items: flex-end;">
                                <button type="submit" name="apply" style="background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer;">
                                    <i class="fas fa-filter"></i> Apply
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($current_term && $current_academic_year && $current_type && ($is_all_subjects ? $current_subject_id : true)): ?>
            
            <?php if (!empty($sections) && count($sections) > 0): ?>
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;">
                <a href="?class_id=<?= $selected_class_id ?>&term=<?= urlencode($current_term) ?>&type=<?= urlencode($current_type) ?>&academic_year=<?= urlencode($current_academic_year) ?>&subject_id=<?= $current_subject_id ?>" 
                   class="btn btn-sm <?= !$selected_section ? 'btn-primary' : 'btn-outline' ?>">
                    <i class="fas fa-layer-group"></i> All
                </a>
                <?php foreach ($sections as $sec): ?>
                <a href="?class_id=<?= $selected_class_id ?>&term=<?= urlencode($current_term) ?>&type=<?= urlencode($current_type) ?>&academic_year=<?= urlencode($current_academic_year) ?>&subject_id=<?= $current_subject_id ?>&section=<?= $sec ?>" 
                   class="btn btn-sm <?= $selected_section == $sec ? 'btn-primary' : 'btn-outline' ?>">
                    <i class="fas fa-stream"></i> Stream <?= $sec ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Debug Information (Hidden by default, shows for troubleshooting) -->
            <div class="debug-info" id="debugInfo">
                <strong>Debug Info:</strong><br>
                Total Students: <?php echo count($students); ?><br>
                Students with marks: <?php echo count($existing_marks); ?><br>
                <button onclick="toggleDebug()" style="margin-top:5px;padding:3px 10px;cursor:pointer;">Toggle Details</button>
                <div id="debugDetails" style="display:none;margin-top:5px;font-size:11px;max-height:200px;overflow:auto;">
                    <?php 
                    echo "<pre>";
                    echo "Student IDs: " . implode(', ', array_column($students, 'id')) . "\n";
                    echo "Existing marks for: " . implode(', ', array_keys($existing_marks)) . "\n";
                    echo "</pre>";
                    ?>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <form method="POST" action="" id="marksForm">
                        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                        <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                        <input type="hidden" name="term" value="<?= htmlspecialchars($current_term) ?>">
                        <input type="hidden" name="type" value="<?= htmlspecialchars($current_type) ?>">
                        <input type="hidden" name="academic_year" value="<?= htmlspecialchars($current_academic_year) ?>">
                        <input type="hidden" name="subject_id" value="<?= htmlspecialchars($is_all_subjects ? $current_subject_id : $subject_id) ?>">
                        
                        <div class="table-container">
                            <table class="marks-table">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-hashtag"></i> </th>
                                        <th><i class="fas fa-user"></i> Full Name</th>
                                        <th><i class="fas fa-pencil-alt"></i> Test 1  </th>
                                        <th><i class="fas fa-project-diagram"></i> Group Work</th>
                                        <th><i class="fas fa-pencil-alt"></i> Test 2  </th>
                                        <th><i class="fas fa-tasks"></i> Project </th>
                                        <th><i class="fas fa-file-alt"></i> Exams</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $index = 0;
                                    foreach ($students as $student):
                                        $index++; 
                                    ?>
                                    <tr data-student-id="<?php echo $student['id']; ?>">
                                        <td class="locked-cell"><?= $index ?></td>
                                        <td class="student-name-cell locked-cell"><?= htmlspecialchars($student['full_name']) ?></td>
                                        <td>
                                            <input type="number" 
                                                   name="scores[<?php echo $student['id']; ?>][test1]" 
                                                   id="test1_<?php echo $student['id']; ?>"
                                                   class="score-input auto-save"
                                                   min="0" max="30" step="0.1"
                                                 
                                                   value="<?= isset($existing_marks[$student['id']]) ? formatScore($existing_marks[$student['id']]['test1']) : '0' ?>"
                                                   data-student="<?php echo $student['id']; ?>"
                                                   data-field="test1">
                                        </td>
                                        <td>
                                            <input type="number" 
                                                   name="scores[<?php echo $student['id']; ?>][project]" 
                                                   id="project_<?php echo $student['id']; ?>"
                                                   class="score-input auto-save"
                                                   min="0" max="30" step="0.1"
                                                  
                                                   value="<?= isset($existing_marks[$student['id']]) ? formatScore($existing_marks[$student['id']]['project']) : '0' ?>"
                                                   data-student="<?php echo $student['id']; ?>"
                                                   data-field="project">
                                        </td>
                                        <td>
                                            <input type="number" 
                                                   name="scores[<?php echo $student['id']; ?>][test2]" 
                                                   id="test2_<?php echo $student['id']; ?>"
                                                   class="score-input auto-save"
                                                   min="0" max="30" step="0.1"
                                                 
                                                   value="<?= isset($existing_marks[$student['id']]) ? formatScore($existing_marks[$student['id']]['test2']) : '0' ?>"
                                                   data-student="<?php echo $student['id']; ?>"
                                                   data-field="test2">
                                        </td>
                                        <td>
                                            <input type="number" 
                                                   name="scores[<?php echo $student['id']; ?>][exercise]" 
                                                   id="exercise_<?php echo $student['id']; ?>"
                                                   class="score-input auto-save"
                                                   min="0" max="30" step="0.1"
                                                  
                                                   value="<?= isset($existing_marks[$student['id']]) ? formatScore($existing_marks[$student['id']]['exercise']) : '0' ?>"
                                                   data-student="<?php echo $student['id']; ?>"
                                                   data-field="exercise">
                                        </td>
                                        <td>
                                            <input type="number" 
                                                   name="scores[<?php echo $student['id']; ?>][exam_score]" 
                                                   id="exam_score_<?php echo $student['id']; ?>"
                                                   class="score-input auto-save"
                                                   min="0" max="100" step="0.1"
                                                  
                                                   value="<?= isset($existing_marks[$student['id']]) ? formatScore($existing_marks[$student['id']]['exam_score']) : '0' ?>"
                                                   data-student="<?php echo $student['id']; ?>"
                                                   data-field="exam_score">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="btn-group">
                            <button type="submit" name="record_multiple_marks" class="btn btn-success" id="saveAllBtn">
                                <i class='fas fa-save'></i> Save All
                            </button>
                            
                            <?php if (isset($_GET['term']) && isset($_GET['academic_year']) && ($is_all_subjects ? isset($_GET['subject_id']) : true)): ?>
                            <a href="?export_excel=1&class_id=<?= $selected_class_id ?>&subject_id=<?= $is_all_subjects ? $_GET['subject_id'] : $subject_id ?>&term=<?= urlencode($_GET['term']) ?>&academic_year=<?= urlencode($_GET['academic_year']) ?>&type=<?= urlencode($_GET['type']) ?>" 
                               class="btn btn-excel" id="download">
                                <svg class="btn-icon" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/>
                                </svg>
                                Export
                            </a>
                            
                            <button type="button" class="btn btn-info" id="showI">
                                <svg class="btn-icon" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/>
                                </svg>
                                Import
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="alert alert-info" style="background-color: #e3f2fd; color: #0d47a1; padding: 20px; border-radius: 5px; text-align: center;">
                        <i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px;"></i>
                        <h4 style="margin: 10px 0;">Please Select All Filters</h4>
                        <p>To record marks, please select: Term, Academic Year, and Exams Type<?php echo $is_all_subjects ? ', and Subject' : ''; ?> above, then click Apply.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php elseif ($selected_class_id): ?>
            <div class="card">
                <div class="card-body">
                    <p><i class="fas fa-user-slash"></i> No students found in this class.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </div>

    <!-- Import Modal -->
    <div id="importModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
        <div class="modal-content" style="background-color: white; margin: 10% auto; padding: 20px; border-radius: 5px; width: 80%; max-width: 500px;">
            <h3><i class="fas fa-file-import"></i> Import from Excel</h3>
            <div class="import-instructions">
                <strong><i class="fas fa-lightbulb"></i> Instructions:</strong>
                <ul>
                    <li>Use the exported Excel file as template</li>
                    <li>Save the file before uploading</li>
                </ul>
            </div>
            <form id="importForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                
                <div class="form-group">
                    <label for="excelFile"><i class="fas fa-file-excel"></i> Choose Excel File:</label>
                    <input type="file" id="excelFile" name="excel_file" accept=".xlsx,.xls" required>
                </div>
                <div class="form-group">
                    <label for="importTerm"><i class="fas fa-calendar-alt"></i> Term:</label>
                    <select id="importTerm" name="term" required>
                        <option value="">Select Term</option>
                        <option value="Term 1">Term 1</option>
                        <option value="Term 2">Term 2</option>
                        <option value="Term 3">Term 3</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="importType"><i class="fas fa-file-alt"></i> Exams type:</label>
                    <select name="type" id="importType" required>
                        <option value="">Select Exams Type</option>
                        <option value="End of term">End of Term</option>
                        <option value="Mock 1">Mock 1</option>
                        <option value="Mock 2">Mock 2</option>
                        <option value="Mock 3">Mock 3</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="importYear"><i class="fas fa-calendar-week"></i> Academic Year:</label>
                    <select id="importYear" name="academic_year" required>
                        <option value="">Select Academic Year</option>
                        <?php foreach (AcademicYear::getAllYears() as $year): ?>
                        <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($is_all_subjects): ?>
                <div class="form-group">
                    <label for="importSubject"><i class="fas fa-book"></i> Subject:</label>
                    <select id="importSubject" name="subject_id" required>
                        <option value="">Select Subject</option>
                        <?php foreach ($all_subjects as $subject): ?>
                        <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="import_excel" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload & Import
                    </button>
                    <button type="button" class="btn btn-secondary" id="hide">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
                <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
            </form>
        </div>
    </div>
    
    <!-- Auto-save status indicators -->
    <div id="autoSaveStatus">
        <i class="fas fa-save"></i> Auto-saved
    </div>
    <div id="localStorageStatus"></div>
</body>

<script src="../assets/js/main.js"></script>

</body>
</html>