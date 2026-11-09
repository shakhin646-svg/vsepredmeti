<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Конфигурация
$config = [
    'db' => [
        'host' => "localhost",
        'username' => "shakhin_root",
        'password' => "Ali12345678",
        'dbname' => "shakhin_root",
        'charset' => "utf8"
    ],
    'content_types' => [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'video' => ['mp4', 'webm', 'ogg'],
        'audio' => ['mp3', 'wav', 'oga'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt']
    ]
];

/**
 * Нормализует строку для использования в пути
 */
function normalizePath($string) {
    $string = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ]/u', '_', $string);
    return mb_strtolower($string);
}

/**
 * Определяет тип контента по расширению файла
 */
function getContentType($filename, $config) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    foreach ($config['content_types'] as $type => $extensions) {
        if (in_array($extension, $extensions)) {
            return $type;
        }
    }
    
    return 'other';
}

/**
 * Устанавливает соединение с базой данных
 */
function getDbConnection($config) {
    $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}";
    $pdo = new PDO($dsn, $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// Обработка AJAX-запросов
if (isset($_GET['ajax'])) {
    try {
        $pdo = getDbConnection($config);
        
        switch ($_GET['ajax']) {
            case 'get_topics':
                $subject_id = (int)($_GET['subject_id'] ?? 0);
                if (!$subject_id) {
                    echo json_encode(['error' => "Не указан subject_id"]);
                    exit;
                }
                $stmt = $pdo->prepare("SELECT id, topic_name, description FROM topics WHERE subject_id = ? ORDER BY topic_name ASC");
                $stmt->execute([$subject_id]);
                $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($topics as &$topic) {
                    $topic['description'] = $topic['description'] ?? "Описание отсутствует";
                }
                
                echo json_encode($topics);
                break;
                
            case 'get_materials':
                $topic_id = (int)($_GET['topic_id'] ?? 0);
                if (!$topic_id) {
                    echo json_encode(['error' => "Не указан topic_id"]);
                    exit;
                }
                
                $stmt = $pdo->prepare("SELECT t.topic_name, s.name AS subject_name 
                                     FROM topics t 
                                     JOIN subjects s ON t.subject_id = s.id 
                                     WHERE t.id = ?");
                $stmt->execute([$topic_id]);
                $topic_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$topic_info) {
                    echo json_encode(['error' => "Тема не найдена"]);
                    exit;
                }
                
                $normalizedSubject = normalizePath($topic_info['subject_name']);
                $normalizedTopic = normalizePath($topic_info['topic_name']);
                $imageDir = "images/subject/{$normalizedSubject}/{$normalizedTopic}/";
                
                if (!is_dir($imageDir)) {
                    echo json_encode(['error' => "Директория с материалами не найдена"]);
                    exit;
                }
                
                $extensions = array_merge(...array_values($config['content_types']));
                $pattern = $imageDir . '*.{' . implode(',', $extensions) . '}';
                $files = glob($pattern, GLOB_BRACE);
                
                $materials = array_map(function($file) use ($config) {
                    return [
                        'title' => basename($file),
                        'file_path' => $file,
                        'file_type' => getContentType($file, $config),
                        'file_url' => $file
                    ];
                }, $files);
                
                echo json_encode($materials);
                break;
                
            case 'get_educational_teachers':
                $educational_id = (int)($_GET['educational_id'] ?? 0);
                if (!$educational_id) {
                    echo json_encode(['error' => "Не указан educational_id"]);
                    exit;
                }
                
                $stmt = $pdo->prepare("SELECT id, fio FROM teachers WHERE educational_id = ? ORDER BY fio ASC");
                $stmt->execute([$educational_id]);
                $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode($teachers);
                break;
                
            case 'get_teacher_courses':
                $teacher_id = (int)($_GET['teacher_id'] ?? 0);
                if (!$teacher_id) {
                    echo json_encode(['error' => "Не указан teacher_id"]);
                    exit;
                }
                
                // Получаем курсы преподавателя
                $stmt = $pdo->prepare("SELECT DISTINCT c.id, c.name 
                                     FROM courses c 
                                     JOIN course_subjects cs ON c.id = cs.course_id 
                                     WHERE cs.teacher_id = ? 
                                     ORDER BY c.name ASC");
                $stmt->execute([$teacher_id]);
                $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode($courses);
                break;
                
            case 'get_teacher_course_subjects':
                $teacher_id = (int)($_GET['teacher_id'] ?? 0);
                $course_id = (int)($_GET['course_id'] ?? 0);
                if (!$teacher_id || !$course_id) {
                    echo json_encode(['error' => "Не указан teacher_id или course_id"]);
                    exit;
                }
                
                // Получаем предметы преподавателя в конкретном курсе
                $stmt = $pdo->prepare("SELECT DISTINCT s.id, s.name 
                                     FROM course_subjects cs 
                                     JOIN subjects s ON cs.subject_id = s.id 
                                     WHERE cs.teacher_id = ? AND cs.course_id = ? 
                                     ORDER BY s.name ASC");
                $stmt->execute([$teacher_id, $course_id]);
                $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode($subjects);
                break;
                
            default:
                echo json_encode(['error' => "Неизвестный AJAX-запрос"]);
        }
        
        exit;
    } catch (PDOException $e) {
        echo json_encode(['error' => "Ошибка выполнения запроса: " . $e->getMessage()]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Основная обработка страницы
$conn = new mysqli($config['db']['host'], $config['db']['username'], $config['db']['password'], $config['db']['dbname']);

if ($conn->connect_error) {
    die("Ошибка подключения к базе данных: " . $conn->connect_error);
}

// Инициализация переменных
$selected_subject = isset($_GET['subject']) ? (int)$_GET['subject'] : null;
$selected_topic = isset($_GET['topic']) ? (int)$_GET['topic'] : null;

// Получаем все образовательные учреждения
$educationals_result = $conn->query("SELECT id, name FROM educational ORDER BY name ASC");
$educationals = $educationals_result ? $educationals_result->fetch_all(MYSQLI_ASSOC) : [];

// Получаем всех преподавателей
$teachers_result = $conn->query("SELECT id, fio, educational_id FROM teachers ORDER BY fio ASC");
$all_teachers = $teachers_result ? $teachers_result->fetch_all(MYSQLI_ASSOC) : [];

if ($selected_subject) {
    $topics = getTopics($conn, $selected_subject);
    
    if ($selected_topic) {
        $topic_info = getTopicInfo($conn, $selected_topic);
        if ($topic_info) {
            $normalizedSubject = normalizePath($topic_info['subject_name']);
            $normalizedTopic = normalizePath($topic_info['topic_name']);
            $imageDir = "images/subject/{$normalizedSubject}/{$normalizedTopic}/";
            
            if (is_dir($imageDir)) {
                $extensions = array_merge(...array_values($GLOBALS['config']['content_types']));
                $pattern = $imageDir . '*.{' . implode(',', $extensions) . '}';
                $files = glob($pattern, GLOB_BRACE);
                
                foreach ($files as $file) {
                    $materials[] = [
                        'title' => basename($file),
                        'file_path' => $file,
                        'file_type' => getContentType($file, $GLOBALS['config']),
                        'file_url' => $file
                    ];
                }
            }
        }
    }
}

$conn->close();

// Вспомогательные функции
function getTopics($conn, $subject_id) {
    $sql = "SELECT id, topic_name, description FROM topics WHERE subject_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Ошибка подготовки запроса тем: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("i", $subject_id);
    if (!$stmt->execute()) {
        error_log("Ошибка выполнения запроса тем: " . $stmt->error);
        return [];
    }
    
    $result = $stmt->get_result();
    $topics = [];
    while ($row = $result->fetch_assoc()) {
        $topics[] = [
            'id' => $row['id'],
            'topic_name' => $row['topic_name'],
            'description' => $row['description'] ?? "Описание отсутствует"
        ];
    }
    return $topics;
}

function getTopicInfo($conn, $topic_id) {
    $sql = "SELECT t.topic_name, s.name AS subject_name 
            FROM topics t 
            JOIN subjects s ON t.subject_id = s.id 
            WHERE t.id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Ошибка подготовки запроса информации о теме: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $topic_id);
    if (!$stmt->execute()) {
        error_log("Ошибка выполнения запроса информации о теме: " . $stmt->error);
        return false;
    }
    
    $result = $stmt->get_result();
    return $result->num_rows > 0 ? $result->fetch_assoc() : false;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moburok.ru - образовательная платформа</title>
    
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Yandex.Metrika counter -->
<script type="text/javascript">
    (function(m,e,t,r,i,k,a){
        m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
        m[i].l=1*new Date();
        for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
        k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
    })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id=104079064', 'ym');

    ym(104079064, 'init', {ssr:true, webvisor:true, clickmap:true, ecommerce:"dataLayer", accurateTrackBounce:true, trackLinks:true});
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/104079064" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
<!-- /Yandex.Metrika counter -->
    
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --primary-hover: #3d8a3f;
            --bg-light: #f0d1d1;
            --educational-color: #0515f0;
            --teachers-color: #0515f0;
            --subjects-color: #0515f0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: white;
            color: #000000;
            line-height: 1.6;
            padding: 10px;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .materials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }

        .material-card {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 2px 2px 2px 1px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s;
            background: #fffafa;
            display: flex;
            flex-direction: column;
            cursor: pointer;
        }

        .material-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .material-thumbnail {
            width: 80%;
            height: 80px;
            object-fit: cover;
        }

        .material-info {
            padding: 1px;
            flex-grow: 1;
        }

        .material-title {
            font-size: 16px;
            font-weight: 50;
            margin-bottom: 5px;
            color: #000000;
            word-break: break-word;
        }

        .material-type {
            font-size: 16px;
            color: #000000;
            padding: 1px 2px;
            border-radius: 1px;
            display: inline-block;
        }

        .type-image,  
        .type-video, 
        .type-audio, 
        .type-document {
            background-color: #ffffff;
        }

        .type-other {
            background-color: #ffffff;
        }

        h1, h2 {
            text-align: center;
            color: #1eb300;
        }

        .section {
            background: #fffffff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            text-align: center;
            border: 1px solid var(--bg-light);
        }

        .subject-button {
            display: inline-block;
            padding: 10px 20px;
            color: #ffffff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
            text-decoration: none;
            background-color: var(--subjects-color);
            width: calc(100% - 10px);
            font-size: 16px;
            margin: 5px;
        }
        
        .subject-button:hover {
            background-color: #38803a;
        }

        .topic-button {
            display: inline-block;
            padding: 10px 20px;
            color: #ffffff;
            border: none;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
            text-decoration: none;
            background-color: var(--subjects-color);
            border-radius: 4px;
            margin: 5px;
            padding: 8px 12px;
            font-size: 16px;
        }

        .educational-button {
            display: inline-block;
            padding: 10px 20px;
            color: #ffffff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
            text-decoration: none;
            background-color: var(--educational-color);
            width: calc(100% - 10px);
            font-size: 16px;
            margin: 5px;
        }

        .educational-button:hover {
            background-color: #1976D2;
        }

        .teacher-item {
            background-color: var(--teachers-color);
            color: #ffffff;
            padding: 10px 15px;
            border-radius: 4px;
            margin: 5px;
            display: inline-block;
            font-size: 16px;
            cursor: pointer;
            border: none;
        }

        .course-button {
            background-color: #0515f0;
            color: #ffffff;
            padding: 10px 15px;
            border-radius: 4px;
            margin: 5px;
            display: inline-block;
            font-size: 16px;
            cursor: pointer;
            border: none;
        }
 
        .topic-button:hover, .subject-button:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
        }

        .active-subject,
        .active-topic,
        .active-educational,
        .active-teacher,
        .active-course {
            background-color: var(--primary-hover) !important;
        }

        .hidden {
            display: none !important;
        }

        .empty-message {
            text-align: center;
            padding: 20px;
            color: #7a7a7a;
            font-style: italic;
        }

        .loading {
            color: #7a7a7a;
            font-style: italic;
            padding: 20px;
            text-align: center;
        }

        .error {
            color: #7a7a7a;
            padding: 20px;
            text-align: center;
            background-color: #ffebee;
            border-radius: 8px;
            margin: 10px 0;
        }

        .topics-list,
        .teachers-list,
        .courses-list,
        .subjects-list {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
        }

        .fade-in {
            opacity: 1;
            height: auto;
            transition: all 0.3s ease;
        }

        .sub-section {
            margin-top: 15px;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 5px;
        }

        .navigation-path {
            font-size: 0.9em;
            color: #666;
            margin-bottom: 10px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .container {
                border-radius: 0;
                box-shadow: none;
            }
            
            .materials-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }
        }
    </style>    
</head>

<body>
    <div class="container">
        
               <div class="header">
             <h1 class="title">Moburok</h1>
         </div>
        <!-- Блок образовательных учреждений -->
        <div class="section" style="margin-bottom: 1px;">
             <p style="font-size: 0.9em; margin-bottom: 10px;">Выберите учреждение для фильтрации преподавателей</p>
            
            <?php if (empty($educationals)): ?>
                <p class="empty-message">Нет доступных образовательных учреждений</p>
            <?php else: ?>
                <div class="educational-buttons">
                    
                    <?php foreach ($educationals as $educational): ?>
                        <button class="educational-button" 
                            data-educational-id="<?php echo $educational['id']; ?>"
                            data-original-text="<?php echo htmlspecialchars($educational['name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($educational['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Блок всех преподавателей -->
        <div class="section" style="margin-bottom: 1px;">
            <h2 style="margin-bottom: 10px;">Преподаватели</h2>
            <p style="font-size: 0.9em; margin-bottom: 10px;">Выберите преподавателя</p>
            
            <?php if (empty($all_teachers)): ?>
                <p class="empty-message">Нет доступных преподавателей</p>
            <?php else: ?>
                <div id="allTeachersList" class="teachers-list">
                    <?php foreach ($all_teachers as $teacher): ?>
                        <button class="teacher-item" 
                            data-teacher-id="<?php echo $teacher['id']; ?>"
                            data-teacher-name="<?php echo htmlspecialchars($teacher['fio'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-educational-id="<?php echo $teacher['educational_id']; ?>">
                            <?php echo htmlspecialchars($teacher['fio'], ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                
                <!-- Контейнер для курсов выбранного преподавателя -->
                <div id="teacherCoursesContainer" style="display: none;">
                    <h3 id="selectedTeacherTitle" style="font-size: 1.2rem; text-align: center; margin-top: 15px;"></h3>
                    <div id="teacherCoursesList" class="courses-list" style="margin: 10px 0;"></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Блок предметов преподавателя -->
        <div id="teacherSubjectsContainer" style="display: none;" class="section">
            <h2 style="margin-bottom: 10px;">Предметы преподавателя</h2>
            <p style="font-size: 0.9em; margin-bottom: 10px;">Выберите предмет для просмотра материалов</p>
            <div id="teacherSubjectsList" class="subjects-list" style="margin: 10px 0;"></div>
        </div>
        
        <!-- Блок тем -->
        <div id="topicsContainer" style="display: none;" class="section">
            <h3 id="selectedSubjectTitle" style="font-size: 1.2rem; text-align: center; margin-top: 15px;"></h3>
            <div id="topicsList" class="topics-list" style="margin: 10px 0;"></div>
            
            <div id="topicDescription" style="display: none; margin-top: 15px; padding: 15px; background-color: #f5f5f5; border-radius: 5px;">
                <h4 style="margin-bottom: 10px; color: #333;">Описание темы</h4>
                <p id="descriptionText" style="color: #666;">Описание отсутствует</p>
            </div>
        </div>
        
        <!-- Блок материалов -->
        <div class="section" style="margin-bottom: 10px;">
            <h2 style="margin-bottom: 10px;">Материалы урока</h2>
            <div id="lessonMaterials">
                <p class="empty-message">Выберите предмет и тему для просмотра материалов</p>
            </div>
        </div>
    </div>
         <!-- Основная игровая панель -->
              <a href="https://shakhin.site/" class="external-link" 
   style="display: block; width: fit-content; margin: 0 auto;" 
   target="_blank" rel="noopener noreferrer">
  <i class="fas fa-external-link-alt"></i> Разработчик образовательной платформы
</a>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const config = {
        selectors: {
            educationalButtons: '.educational-button',
            teacherItems: '.teacher-item',
            courseButtons: '.course-button',
            teacherCoursesContainer: '#teacherCoursesContainer',
            teacherSubjectsContainer: '#teacherSubjectsContainer',
            topicsContainer: '#topicsContainer',
            allTeachersList: '#allTeachersList',
            teacherCoursesList: '#teacherCoursesList',
            teacherSubjectsList: '#teacherSubjectsList',
            topicsList: '#topicsList',
            selectedTeacherTitle: '#selectedTeacherTitle',
            selectedSubjectTitle: '#selectedSubjectTitle',
            topicDescription: '#topicDescription',
            descriptionText: '#descriptionText',
            lessonMaterials: '#lessonMaterials'
        },
        classes: {
            hidden: 'hidden',
            error: 'error',
            emptyMessage: 'empty-message',
            activeSubject: 'active-subject',
            activeTopic: 'active-topic',
            activeEducational: 'active-educational',
            activeTeacher: 'active-teacher',
            activeCourse: 'active-course'
        }
    };

    const elements = {};
    for (const [key, selector] of Object.entries(config.selectors)) {
        elements[key] = document.querySelector(selector);
    }

    const state = {
        currentEducational: 0, // 0 = все учреждения
        currentTeacher: null,
        currentCourse: null,
        currentSubject: null,
        currentTopic: null,
        isTopicExpanded: false
    };

    function init() {
        bindEvents();
        // Показать всех преподавателей при загрузке
        filterTeachers();
    }

    function bindEvents() {
        document.querySelectorAll(config.selectors.educationalButtons).forEach(button => {
            button.addEventListener('click', handleEducationalClick);
        });

        document.querySelectorAll(config.selectors.teacherItems).forEach(button => {
            button.addEventListener('click', handleTeacherClick);
        });
    }

    function handleEducationalClick(event) {
        const button = event.currentTarget;
        const educationalId = parseInt(button.dataset.educationalId);
        const educationalName = button.dataset.originalText;

        if (state.currentEducational === educationalId) {
            return;
        }

        // Убрать активный класс со всех кнопок учреждений
        document.querySelectorAll(config.selectors.educationalButtons).forEach(btn => {
            btn.classList.remove(config.classes.activeEducational);
        });

        // Добавить активный класс к выбранной кнопке
        button.classList.add(config.classes.activeEducational);

        state.currentEducational = educationalId;
        filterTeachers();
        
        // Сбросить выбор преподавателя
        resetTeacherSelection();
    }

    function handleTeacherClick(event) {
        const button = event.currentTarget;
        const teacherId = parseInt(button.dataset.teacherId);
        const teacherName = button.dataset.teacherName;

        if (state.currentTeacher === teacherId) {
            resetTeacherSelection();
            return;
        }

        // Убрать активный класс со всех преподавателей
        document.querySelectorAll(config.selectors.teacherItems).forEach(btn => {
            btn.classList.remove(config.classes.activeTeacher);
        });

        // Добавить активный класс к выбранному преподавателю
        button.classList.add(config.classes.activeTeacher);

        state.currentTeacher = teacherId;
        
        // Показать курсы этого преподавателя
        loadTeacherCourses(teacherId, teacherName);
        
        // Скрыть предметы и материалы
        resetSubjectSelection();
        resetTopicSelection();
    }

    function handleTeacherCourseClick(event) {
        const courseId = parseInt(event.currentTarget.dataset.courseId);
        const courseName = event.currentTarget.dataset.courseName;
        const teacherName = document.querySelector('.teacher-item.active-teacher')?.dataset.teacherName || '';

        // Убрать активный класс со всех курсов
        document.querySelectorAll(config.selectors.courseButtons).forEach(btn => {
            btn.classList.remove(config.classes.activeCourse);
        });

        // Добавить активный класс к выбранному курсу
        event.currentTarget.classList.add(config.classes.activeCourse);

        state.currentCourse = courseId;
        loadTeacherCourseSubjects(state.currentTeacher, teacherName, courseId, courseName);
    }

    function handleSubjectClick(event) {
        const button = event.currentTarget;
        const subjectId = parseInt(button.dataset.subjectId);
        const subjectName = button.dataset.subjectName;

        if (state.currentSubject === subjectId) {
            resetSubjectSelection();
            return;
        }

        // Убрать активный класс со всех предметов
        document.querySelectorAll('.subject-button').forEach(btn => {
            btn.classList.remove(config.classes.activeSubject);
        });

        // Добавить активный класс к выбранному предмету
        button.classList.add(config.classes.activeSubject);

        state.currentSubject = subjectId;
        loadTopics(subjectId, subjectName);
    }

    function handleTopicClick(event) {
        const button = event.currentTarget;
        const topicId = parseInt(button.dataset.topicId);
        const description = button.dataset.description;

        if (state.currentTopic === topicId) {
            toggleTopicVisibility();
            return;
        }

        // Убрать активный класс со всех тем
        document.querySelectorAll('.topic-button').forEach(btn => {
            btn.classList.remove(config.classes.activeTopic);
        });

        // Добавить активный класс к выбранной теме
        button.classList.add(config.classes.activeTopic);

        state.currentTopic = topicId;
        state.isTopicExpanded = true;
        showTopicDescription(description);
        loadMaterials(state.currentSubject, topicId);
    }

    function filterTeachers() {
        const educationalId = state.currentEducational;
        
        // Фильтрация преподавателей
        document.querySelectorAll(config.selectors.teacherItems).forEach(teacher => {
            const teacherEducationalId = parseInt(teacher.dataset.educationalId);
            if (educationalId === 0 || teacherEducationalId === educationalId) {
                teacher.style.display = 'inline-block';
            } else {
                teacher.style.display = 'none';
                teacher.classList.remove(config.classes.activeTeacher);
            }
        });

        // Сбросить выбор преподавателя если он не соответствует фильтру
        if (state.currentTeacher && educationalId !== 0) {
            const activeTeacher = document.querySelector('.teacher-item.active-teacher');
            if (activeTeacher) {
                const teacherEducationalId = parseInt(activeTeacher.dataset.educationalId);
                if (teacherEducationalId !== educationalId) {
                    resetTeacherSelection();
                }
            }
        }
    }

    async function fetchData(url, params = {}) {
        try {
            const queryString = new URLSearchParams(params).toString();
            const response = await fetch(`${url}?${queryString}`);
            
            if (!response.ok) {
                throw new Error('Ошибка сети');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Ошибка загрузки данных:', error);
            return { error: error.message };
        }
    }

    async function loadTeacherCourses(teacherId, teacherName) {
        elements.teacherCoursesList.innerHTML = '';
        elements.selectedTeacherTitle.textContent = ` ${teacherName}`;
        elements.teacherCoursesContainer.style.display = 'block';

        const data = await fetchData('', { ajax: 'get_teacher_courses', teacher_id: teacherId });
        
        if (data.error) {
            showError(elements.teacherCoursesList, data.error);
            return;
        }

        if (data.length === 0) {
            showEmptyMessage(elements.teacherCoursesList, 'У преподавателя нет курсов');
            return;
        }

        renderCourses(data, elements.teacherCoursesList);
        bindTeacherCourseEvents();
    }

    async function loadTeacherCourseSubjects(teacherId, teacherName, courseId, courseName) {
        const data = await fetchData('', { 
            ajax: 'get_teacher_course_subjects', 
            teacher_id: teacherId, 
            course_id: courseId 
        });
        
        if (data.error) {
            showError(elements.teacherSubjectsList, data.error);
            return;
        }

        if (data.length === 0) {
            showEmptyMessage(elements.teacherSubjectsList, 'У преподавателя нет предметов на этом курсе');
            return;
        }

        elements.teacherSubjectsContainer.style.display = 'block';
        elements.selectedSubjectTitle.textContent = `Предметы: ${teacherName} - ${courseName}`;
        renderSubjects(data);
        bindSubjectEvents();
    }

    async function loadTopics(subjectId, subjectName) {
        elements.topicsList.innerHTML = '';
        elements.topicsContainer.style.display = 'block';
        elements.selectedSubjectTitle.textContent = `Темы: ${subjectName}`;

        const data = await fetchData('', { ajax: 'get_topics', subject_id: subjectId });
        
        if (data.error) {
            showError(elements.topicsList, data.error);
            return;
        }

        if (data.length === 0) {
            showEmptyMessage(elements.topicsList, 'Нет тем для этого предмета');
            return;
        }

        renderTopics(data);
        bindTopicEvents();
    }

    async function loadMaterials(subjectId, topicId) {
        const data = await fetchData('', { ajax: 'get_materials', topic_id: topicId });
        
        if (data.error) {
            showError(elements.lessonMaterials, data.error);
            return;
        }

        if (data.length === 0) {
            showEmptyMessage(elements.lessonMaterials, 'Материалы для этой темы не найдены');
        } else {
            renderMaterials(data);
        }
        
        elements.lessonMaterials.style.display = 'block';
    }

    function renderCourses(courses, container) {
        container.innerHTML = courses.map(course => `
            <button class="course-button" 
                data-course-id="${course.id}"
                data-course-name="${escapeHtml(course.name)}">
                ${escapeHtml(course.name)}
            </button>
        `).join('');
    }

    function renderSubjects(subjects) {
        elements.teacherSubjectsList.innerHTML = subjects.map(subject => `
            <button class="subject-button" 
                data-subject-id="${subject.id}"
                data-subject-name="${escapeHtml(subject.name)}">
                ${escapeHtml(subject.name)}
            </button>
        `).join('');
    }

    function renderTopics(topics) {
        elements.topicsList.innerHTML = topics.map(topic => `
            <button class="topic-button" 
                data-topic-id="${topic.id}"
                data-description="${escapeHtml(topic.description || '')}">
                ${escapeHtml(topic.topic_name)}
            </button>
        `).join('');
    }

    function renderMaterials(materials) {
        elements.lessonMaterials.innerHTML = `
            <div class="materials-container fade-in">
                <div class="materials-grid">
                    ${materials.map(material => renderMaterialCard(material)).join('')}
                </div>
            </div>
        `;
    }

    function renderMaterialCard(material) {
        const icon = getMaterialIcon(material.file_type, material.file_url);
        const typeLabel = getMaterialTypeLabel(material.file_type);
        
        return `
            <div class="material-card" onclick="openMaterial('${escapeSingleQuotes(material.file_url)}', '${material.file_type}')">
                <div style="position: relative;">
                    ${icon}
                </div>
                <div class="material-info">
                    <div class="material-title">${escapeHtml(material.title)}</div>
                    <span class="material-type type-${material.file_type}">
                        ${typeLabel}
                    </span>
                </div>
            </div>
        `;
    }

    function getMaterialIcon(type, url) {
        const icons = {
            image: `<img src="${url}" class="material-thumbnail" alt="">`,
            video: `<video class="material-thumbnail" style="background: black;">
                      <source src="${url}" type="video/mp4">
                   </video>`,
            audio: `<div class="material-thumbnail" style="background: #f5f5f5; display: flex; align-items: center; justify-content: center;">
                      <i class="fas fa-music" style="font-size: 30px; color: #9c27b0;"></i>
                    </div>`,
            document: `<div class="material-thumbnail" style="background: #f5f5f5; display: flex; align-items: center; justify-content: center;">
                         <i class="fas fa-file-alt" style="font-size: 30px; color: #ff9800;"></i>
                       </div>`,
            default: `<div class="material-thumbnail" style="background: #f5f5f5; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-file" style="font-size: 30px; color: #6c757d;"></i>
                      </div>`
        };
        
        return icons[type] || icons.default;
    }

    function getMaterialTypeLabel(type) {
        const labels = {
            image: 'Изображение',
            video: 'Видео',
            audio: 'Аудио',
            document: 'Текст'
        };
        return labels[type] || 'Файл';
    }

    function showTopicDescription(description) {
        elements.descriptionText.textContent = description || 'Описание отсутствует';
        elements.topicDescription.style.display = 'block';
    }

    function toggleTopicVisibility() {
        state.isTopicExpanded = !state.isTopicExpanded;
        elements.lessonMaterials.style.display = state.isTopicExpanded ? 'block' : 'none';
    }

    function resetTeacherSelection() {
        document.querySelectorAll(config.selectors.teacherItems).forEach(btn => {
            btn.classList.remove(config.classes.activeTeacher);
        });
        elements.teacherCoursesContainer.style.display = 'none';
        elements.teacherSubjectsContainer.style.display = 'none';
        state.currentTeacher = null;
        state.currentCourse = null;
    }

    function resetSubjectSelection() {
        document.querySelectorAll('.subject-button').forEach(btn => {
            btn.classList.remove(config.classes.activeSubject);
        });
        elements.topicsContainer.style.display = 'none';
        elements.topicDescription.style.display = 'none';
        state.currentSubject = null;
    }

    function resetTopicSelection() {
        document.querySelectorAll('.topic-button').forEach(btn => {
            btn.classList.remove(config.classes.activeTopic);
        });
        elements.lessonMaterials.style.display = 'none';
        state.currentTopic = null;
        state.isTopicExpanded = false;
    }

    function bindTeacherCourseEvents() {
        document.querySelectorAll('#teacherCoursesList .course-button').forEach(button => {
            button.addEventListener('click', handleTeacherCourseClick);
        });
    }

    function bindSubjectEvents() {
        document.querySelectorAll('#teacherSubjectsList .subject-button').forEach(button => {
            button.addEventListener('click', handleSubjectClick);
        });
    }

    function bindTopicEvents() {
        document.querySelectorAll('#topicsList .topic-button').forEach(button => {
            button.addEventListener('click', handleTopicClick);
        });
    }

    function showError(container, message) {
        container.innerHTML = `<p class="${config.classes.error}">${escapeHtml(message)}</p>`;
    }

    function showEmptyMessage(container, message) {
        container.innerHTML = `<p class="${config.classes.emptyMessage}">${escapeHtml(message)}</p>`;
    }

    function escapeSingleQuotes(str) {
        return str.replace(/'/g, "\\'");
    }

    init();
});

function openMaterial(fileUrl, contentType) {
    window.open(fileUrl, '_blank');
}

function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>
</body>
</html>