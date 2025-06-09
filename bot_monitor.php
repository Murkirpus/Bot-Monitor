<?php
/**
 * Универсальный Bot Monitor для DLE 15.2+
 * Файл: bot_monitor.php
 * 
 * Совместим со всеми версиями DLE начиная с 15.2
 * Автоматически определяет версию и подстраивается
 */

// Проверяем наличие DLE
if (!file_exists('engine/data/config.php')) {
    die('Ошибка: Поместите файл bot_monitor.php в корень сайта DLE');
}

// Подключаемся к DLE
define('DATALIFEENGINE', true);
define('ENGINE_DIR', dirname(__FILE__) . '/engine');
define('ROOT_DIR', dirname(__FILE__));

// Подключаем конфигурацию
require_once ENGINE_DIR . '/data/config.php';

// Универсальное подключение классов БД для разных версий DLE
$db_classes = [
    ENGINE_DIR . '/classes/mysqli.php',
    ENGINE_DIR . '/classes/mysql.php', 
    ENGINE_DIR . '/classes/pdo.php',
    ENGINE_DIR . '/modules/base/core/db.php',
    ENGINE_DIR . '/classes/db.php',
    ENGINE_DIR . '/inc/db.php'
];

$db_loaded = false;
foreach ($db_classes as $db_class) {
    if (file_exists($db_class)) {
        require_once $db_class;
        $db_loaded = true;
        break;
    }
}

if (!$db_loaded) {
    die('Ошибка: Не найден класс для работы с БД. Проверенные пути:<br>' . implode('<br>', $db_classes));
}

// Теперь подключаем dbconfig ПОСЛЕ загрузки класса db
require_once ENGINE_DIR . '/data/dbconfig.php';

// Если $db еще не создан, создаем его
if (!isset($db) || !$db) {
    // Пробуем разные способы создания подключения
    if (class_exists('db')) {
        $db = new db();
    } elseif (class_exists('mysqli_db')) {
        $db = new mysqli_db($db_host, $db_user, $db_pass, $db_name, $db_port);
    } elseif (class_exists('mysql_db')) {
        $db = new mysql_db($db_host, $db_user, $db_pass, $db_name);
    } else {
        die('Ошибка: Не удалось создать подключение к БД');
    }
}

class UniversalBotMonitor {
   private $db;
   private $table_name;
   private $config;
   private $dle_version;
   
   public function __construct() {
       global $db, $config;
       $this->db = $db;
       $this->config = $config;
       $this->table_name = PREFIX . '_bot_visits';
       
       // Определяем версию DLE
       $this->dle_version = $this->detect_dle_version();
       
       // Создаем таблицу если не существует
       $this->create_table_if_not_exists();
       
       // Обрабатываем действия
       $this->handle_actions();
   }
   
   private function detect_dle_version() {
       global $config;
       
       // Пробуем разные способы определения версии
       if (isset($config['version_id'])) {
           return floatval($config['version_id']);
       } elseif (defined('VERSION_ID')) {
           return floatval(VERSION_ID);
       } elseif (isset($config['version'])) {
           return floatval($config['version']);
       }
       
       // Если не удалось определить, возвращаем минимальную поддерживаемую
       return 15.2;
   }
   
   // Универсальный метод для экранирования строк
   private function escape_string($value) {
       if (method_exists($this->db, 'safesql')) {
           return $this->db->safesql($value);
       } elseif (method_exists($this->db, 'escape')) {
           return $this->db->escape($value);
       } elseif (method_exists($this->db, 'real_escape_string')) {
           return $this->db->real_escape_string($value);
       } elseif (method_exists($this->db, 'escape_string')) {
           return $this->db->escape_string($value);
       } else {
           return addslashes($value);
       }
   }
   
   // Универсальный метод для выполнения запросов
   private function db_query($query) {
       if (method_exists($this->db, 'query')) {
           return $this->db->query($query);
       } elseif (method_exists($this->db, 'super_query')) {
           return $this->db->super_query($query, false);
       } else {
           return false;
       }
   }
   
   // Универсальный метод для получения одной строки
   private function db_super_query($query, $multi = false) {
       if (method_exists($this->db, 'super_query')) {
           return $this->db->super_query($query, $multi);
       } elseif (method_exists($this->db, 'query_first')) {
           return $this->db->query_first($query);
       } elseif (method_exists($this->db, 'fetch_array')) {
           $result = $this->db->query($query);
           if ($multi) {
               $rows = [];
               while ($row = $this->db->fetch_array($result)) {
                   $rows[] = $row;
               }
               return $rows;
           } else {
               return $this->db->fetch_array($result);
           }
       } else {
           return false;
       }
   }
   
   private function create_table_if_not_exists() {
       $table_check = $this->db_super_query("SHOW TABLES LIKE '{$this->table_name}'");
       if (!$table_check) {
           $this->db_query("CREATE TABLE IF NOT EXISTS `{$this->table_name}` (
               `id` int(11) NOT NULL AUTO_INCREMENT,
               `bot_name` varchar(100) NOT NULL,
               `user_agent` text NOT NULL,
               `ip_address` varchar(45) NOT NULL,
               `url` varchar(500) NOT NULL,
               `referer` varchar(500) DEFAULT NULL,
               `visit_time` datetime DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               KEY `bot_name` (`bot_name`),
               KEY `visit_time` (`visit_time`),
               KEY `ip_address` (`ip_address`)
           ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
       }
   }
   
   private function handle_actions() {
       $action = $_GET['action'] ?? '';
       
       switch ($action) {
           case 'install_tracker':
               $this->install_tracker();
               break;
           case 'uninstall':
               $this->uninstall_tracker();
               break;
           case 'stats':
               $this->show_stats_page();
               break;
           case 'api':
               $this->api_endpoint();
               break;
           case 'export':
               $this->export_data();
               break;
			case 'manage_bots':
    $this->manage_bots_page();
    break;
	case 'bot_load':
    $this->show_bot_load_page();
    break;
           case 'clear':
               $this->clear_data();
               break;
           default:
               $this->show_main_page();
               break;
       }
   }
   
   // 2. Добавить функцию показа страницы анализа нагрузки:
private function show_bot_load_page() {
    $period = $_GET['period'] ?? '24';
    $period = in_array($period, ['1', '24', '168', '720']) ? $period : '24'; // 1ч, 24ч, 7дн, 30дн
    
    $load_stats = $this->get_bot_load_stats($period);
    
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bot Monitor - Анализ нагрузки от ботов</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { 
            font-family: system-ui, sans-serif; 
            margin: 0; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            min-height: 100vh; 
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; color: white; margin-bottom: 30px; }
        .card { 
            background: rgba(255,255,255,0.95); 
            border-radius: 15px; 
            padding: 25px; 
            margin: 20px 0; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1); 
        }
        .back-link { text-align: center; margin: 20px 0; }
        .back-link a { color: white; text-decoration: none; padding: 10px 20px; background: rgba(255,255,255,0.2); border-radius: 6px; }
        
        .controls { text-align: center; margin: 20px 0; }
        .controls select { 
            padding: 8px 16px; 
            margin: 0 5px; 
            border-radius: 6px; 
            border: 1px solid #ddd;
        }
        
        .chart-container { height: 400px; margin: 20px 0; }
        
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        
        .load-indicator {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .load-low { background: #d4edda; color: #155724; }
        .load-medium { background: #fff3cd; color: #856404; }
        .load-high { background: #f8d7da; color: #721c24; }
        .load-critical { background: #d1ecf1; color: #0c5460; }
        
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .stat-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #007cba;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9em;
        }
        
        .progress-bar {
            background: #e9ecef;
            border-radius: 10px;
            height: 8px;
            margin: 5px 0;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            color: #856404;
        }
        
        .info-box {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            color: #004085;
        }
        
        .bot-details {
            font-size: 0.9em;
            color: #6c757d;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Анализ нагрузки от ботов</h1>
            <p>Какие боты создают наибольшую нагрузку на ваш сайт</p>
            <div class="back-link">
                <a href="bot_monitor.php">← Назад к главной</a>
            </div>
        </div>
        
        <div class="card">
            <div class="controls">
                <label>Период анализа: </label>
                <select onchange="changePeriod(this.value)">
                    <option value="1" <?= $period == '1' ? 'selected' : '' ?>>Последний час</option>
                    <option value="24" <?= $period == '24' ? 'selected' : '' ?>>Последние 24 часа</option>
                    <option value="168" <?= $period == '168' ? 'selected' : '' ?>>Последняя неделя</option>
                    <option value="720" <?= $period == '720' ? 'selected' : '' ?>>Последний месяц</option>
                </select>
            </div>
            
            <div class="stat-grid">
                <div class="stat-item">
                    <div class="stat-number"><?= number_format($load_stats['total_requests']) ?></div>
                    <div class="stat-label">Всего запросов</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= round($load_stats['avg_requests_per_hour'], 1) ?></div>
                    <div class="stat-label">Запросов в час</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $load_stats['active_bots'] ?></div>
                    <div class="stat-label">Активных ботов</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $load_stats['peak_hour_requests'] ?></div>
                    <div class="stat-label">Пик запросов/час</div>
                </div>
            </div>
        </div>
        
        <?php if ($load_stats['high_load_bots']): ?>
        <div class="card">
            <h3>⚠️ Боты с высокой нагрузкой</h3>
            
            <?php 
            $critical_bots = array_filter($load_stats['top_load_bots'], function($bot) {
                return $bot['requests_per_hour'] > 50; // Более 50 запросов в час
            });
            ?>
            
            <?php if ($critical_bots): ?>
            <div class="warning-box">
                <strong>🔥 Критическая нагрузка обнаружена!</strong><br>
                Следующие боты делают очень много запросов и могут замедлять ваш сайт:
                <?php foreach ($critical_bots as $bot): ?>
                    <strong><?= htmlspecialchars($bot['bot_name']) ?></strong> (<?= round($bot['requests_per_hour'], 1) ?> запросов/час),
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <table>
                <thead>
                    <tr>
                        <th>Бот</th>
                        <th>Запросов за период</th>
                        <th>Запросов в час</th>
                        <th>Нагрузка</th>
                        <th>Последняя активность</th>
                        <th>Детали</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($load_stats['top_load_bots'] as $bot): 
                        // Определяем уровень нагрузки
                        $load_level = 'low';
                        $load_text = 'Низкая';
                        $load_color = '#28a745';
                        
                        if ($bot['requests_per_hour'] > 50) {
                            $load_level = 'critical';
                            $load_text = 'Критическая';
                            $load_color = '#dc3545';
                        } elseif ($bot['requests_per_hour'] > 20) {
                            $load_level = 'high';
                            $load_text = 'Высокая';
                            $load_color = '#fd7e14';
                        } elseif ($bot['requests_per_hour'] > 10) {
                            $load_level = 'medium';
                            $load_text = 'Средняя';
                            $load_color = '#ffc107';
                        }
                        
                        // Прогресс-бар для визуализации нагрузки (максимум 100 запросов/час)
                        $progress_percent = min(($bot['requests_per_hour'] / 100) * 100, 100);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($bot['bot_name']) ?></strong></td>
                        <td><?= number_format($bot['total_requests']) ?></td>
                        <td>
                            <?= round($bot['requests_per_hour'], 1) ?>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $progress_percent ?>%; background: <?= $load_color ?>;"></div>
                            </div>
                        </td>
                        <td>
                            <span class="load-indicator load-<?= $load_level ?>">
                                <?= $load_text ?>
                            </span>
                        </td>
                        <td><?= date('d.m.Y H:i', strtotime($bot['last_visit'])) ?></td>
                        <td>
                            <div class="bot-details">
                                IP: <?= htmlspecialchars($bot['unique_ips']) ?> уникальных<br>
                                Страниц: <?= $bot['unique_pages'] ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h3>📈 График нагрузки по времени</h3>
            <div class="chart-container">
                <canvas id="loadChart"></canvas>
            </div>
        </div>
        
        <div class="card">
            <h3>📊 Статистика по часам</h3>
            <?php if ($load_stats['hourly_stats']): ?>
            <table>
                <thead>
                    <tr>
                        <th>Час</th>
                        <th>Запросов</th>
                        <th>Активных ботов</th>
                        <th>Топ бот</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($load_stats['hourly_stats'] as $hour_stat): ?>
                    <tr>
                        <td><?= $hour_stat['hour'] ?>:00</td>
                        <td><?= $hour_stat['requests'] ?></td>
                        <td><?= $hour_stat['unique_bots'] ?></td>
                        <td><?= htmlspecialchars($hour_stat['top_bot']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="text-align: center; color: #6c757d;">Недостаточно данных для анализа по часам</p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <div class="info-box">
                <h4>💡 Как интерпретировать данные:</h4>
                <ul>
                    <li><strong>Низкая нагрузка</strong> (&lt;10 запросов/час) - нормальная активность поисковых ботов</li>
                    <li><strong>Средняя нагрузка</strong> (10-20 запросов/час) - активная индексация, обычно не проблема</li>
                    <li><strong>Высокая нагрузка</strong> (20-50 запросов/час) - стоит следить, может влиять на производительность</li>
                    <li><strong>Критическая нагрузка</strong> (&gt;50 запросов/час) - может серьезно нагружать сервер</li>
                </ul>
                
                <h4>🛠️ Что делать при высокой нагрузке:</h4>
                <ul>
                    <li>Проверьте robots.txt для ограничения частоты сканирования</li>
                    <li>Настройте Crawl-delay для агрессивных ботов</li>
                    <li>Рассмотрите временное ограничение доступа через .htaccess</li>
                    <li>Убедитесь, что это легитимные поисковые боты</li>
                </ul>
            </div>
        </div>
    </div>
    
    <script>
    function changePeriod(period) {
        window.location.href = '?action=bot_load&period=' + period;
    }
    
    // График нагрузки по времени
    const ctx = document.getElementById('loadChart').getContext('2d');
    const chartData = <?= json_encode($load_stats['timeline_data']) ?>;
    
    // Подготавливаем данные для графика
    const labels = chartData.map(item => item.time_label);
    const requestsData = chartData.map(item => parseInt(item.requests));
    const botsData = chartData.map(item => parseInt(item.unique_bots));
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Запросы',
                data: requestsData,
                borderColor: '#FF6384',
                backgroundColor: '#FF638420',
                yAxisID: 'y',
                tension: 0.1
            }, {
                label: 'Активные боты',
                data: botsData,
                borderColor: '#36A2EB',
                backgroundColor: '#36A2EB20',
                yAxisID: 'y1',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: true,
                    text: 'Нагрузка от ботов во времени'
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Количество запросов'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Количество ботов'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });
    </script>
</body>
</html>
    <?php
}

// 3. Добавить функцию получения статистики нагрузки:
private function get_bot_load_stats($period_hours) {
    // Базовая статистика
    $total_requests = $this->db_super_query("
        SELECT COUNT(*) as count 
        FROM `{$this->table_name}` 
        WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$period_hours} HOUR)
    ");
    
    $active_bots = $this->db_super_query("
        SELECT COUNT(DISTINCT bot_name) as count 
        FROM `{$this->table_name}` 
        WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$period_hours} HOUR)
    ");
    
    // Топ ботов по нагрузке
    $top_load_bots = $this->db_super_query("
        SELECT 
            bot_name,
            COUNT(*) as total_requests,
            COUNT(*) / ({$period_hours}) as requests_per_hour,
            COUNT(DISTINCT ip_address) as unique_ips,
            COUNT(DISTINCT url) as unique_pages,
            MAX(visit_time) as last_visit
        FROM `{$this->table_name}` 
        WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$period_hours} HOUR)
        GROUP BY bot_name
        ORDER BY total_requests DESC
        LIMIT 20
    ", 1);
    
    // Пиковая нагрузка по часам
    $peak_hour = $this->db_super_query("
        SELECT COUNT(*) as peak_requests
        FROM `{$this->table_name}` 
        WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$period_hours} HOUR)
        GROUP BY HOUR(visit_time), DATE(visit_time)
        ORDER BY peak_requests DESC
        LIMIT 1
    ");
    
    // Статистика по часам (последние 24 часа или весь период если меньше)
    $hours_to_show = min($period_hours, 24);
    $hourly_stats = $this->db_super_query("
        SELECT 
            HOUR(visit_time) as hour,
            COUNT(*) as requests,
            COUNT(DISTINCT bot_name) as unique_bots,
            (SELECT bot_name FROM `{$this->table_name}` b2 
             WHERE HOUR(b2.visit_time) = HOUR(b1.visit_time) 
             AND b2.visit_time >= DATE_SUB(NOW(), INTERVAL {$hours_to_show} HOUR)
             GROUP BY bot_name ORDER BY COUNT(*) DESC LIMIT 1) as top_bot
        FROM `{$this->table_name}` b1
        WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$hours_to_show} HOUR)
        GROUP BY HOUR(visit_time)
        ORDER BY hour
    ", 1);
    
    // Данные для графика (по часам для периодов до недели, по дням для больших периодов)
    if ($period_hours <= 168) { // До недели - показываем по часам
        $timeline_data = $this->db_super_query("
            SELECT 
                CONCAT(DATE_FORMAT(visit_time, '%d.%m'), ' ', HOUR(visit_time), ':00') as time_label,
                COUNT(*) as requests,
                COUNT(DISTINCT bot_name) as unique_bots
            FROM `{$this->table_name}` 
            WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$period_hours} HOUR)
            GROUP BY DATE(visit_time), HOUR(visit_time)
            ORDER BY visit_time
        ", 1);
    } else { // Больше недели - показываем по дням
        $timeline_data = $this->db_super_query("
            SELECT 
                DATE_FORMAT(visit_time, '%d.%m.%Y') as time_label,
                COUNT(*) as requests,
                COUNT(DISTINCT bot_name) as unique_bots
            FROM `{$this->table_name}` 
            WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$period_hours} HOUR)
            GROUP BY DATE(visit_time)
            ORDER BY visit_time
        ", 1);
    }
    
    // Определяем ботов с высокой нагрузкой (более 10 запросов в час)
    $high_load_bots = array_filter($top_load_bots ?: [], function($bot) {
        return $bot['requests_per_hour'] > 10;
    });
    
    return [
        'total_requests' => $total_requests['count'] ?? 0,
        'avg_requests_per_hour' => ($total_requests['count'] ?? 0) / max($period_hours, 1),
        'active_bots' => $active_bots['count'] ?? 0,
        'peak_hour_requests' => $peak_hour['peak_requests'] ?? 0,
        'top_load_bots' => $top_load_bots ?: [],
        'high_load_bots' => $high_load_bots,
        'hourly_stats' => $hourly_stats ?: [],
        'timeline_data' => $timeline_data ?: []
    ];
}
   
   private function manage_bots_page() {
    // Обработка добавления нового бота
    if ($_POST['action'] ?? '' === 'add_bot') {
        $bot_signature = trim($_POST['bot_signature'] ?? '');
        $bot_name = trim($_POST['bot_name'] ?? '');
        
        if ($bot_signature && $bot_name) {
            // Сохраняем в файл custom_bots.json
            $custom_bots_file = dirname(__FILE__) . '/engine/data/bot_monitor_custom_bots.json';
            $custom_bots = [];
            
            if (file_exists($custom_bots_file)) {
                $custom_bots = json_decode(file_get_contents($custom_bots_file), true) ?: [];
            }
            
            $custom_bots[$bot_signature] = $bot_name;
            
            if (file_put_contents($custom_bots_file, json_encode($custom_bots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                $message = "✅ Бот успешно добавлен: {$bot_name} Боты сохраняются в файл /engine/data/bot_monitor_custom_bots.json и автоматически подхватываются трекером!";
                $message_type = 'success';
            } else {
                $message = "❌ Ошибка при сохранении бота! Боты сохраняются в файл /engine/data/bot_monitor_custom_bots.json и автоматически подхватываются трекером! ";
                $message_type = 'error';
            }
        }
    }
    
    // Обработка удаления бота
    if (($_GET['delete'] ?? '') && ($_GET['confirm'] ?? '') === 'yes') {
        $bot_to_delete = $_GET['delete'];
        $custom_bots_file = dirname(__FILE__) . '/engine/data/bot_monitor_custom_bots.json';
        
        if (file_exists($custom_bots_file)) {
            $custom_bots = json_decode(file_get_contents($custom_bots_file), true) ?: [];
            
            if (isset($custom_bots[$bot_to_delete])) {
                unset($custom_bots[$bot_to_delete]);
                file_put_contents($custom_bots_file, json_encode($custom_bots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $message = "✅ Бот удален";
                $message_type = 'success';
            }
        }
    }
    
    // Получаем список неопознанных User-Agent
    $unknown_agents = $this->get_unknown_user_agents();
    
    // Получаем список пользовательских ботов
    $custom_bots_file = dirname(__FILE__) . '/engine/data/bot_monitor_custom_bots.json';
    $custom_bots = [];
    if (file_exists($custom_bots_file)) {
        $custom_bots = json_decode(file_get_contents($custom_bots_file), true) ?: [];
    }
    
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bot Monitor - Управление ботами</title>
    <style>
        body { 
            font-family: system-ui, sans-serif; 
            margin: 0; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            min-height: 100vh; 
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; color: white; margin-bottom: 30px; }
        .card { 
            background: rgba(255,255,255,0.95); 
            border-radius: 15px; 
            padding: 25px; 
            margin: 20px 0; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1); 
        }
        .back-link { text-align: center; margin: 20px 0; }
        .back-link a { color: white; text-decoration: none; padding: 10px 20px; background: rgba(255,255,255,0.2); border-radius: 6px; }
        
        .form-group { margin: 20px 0; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; }
        .form-group input { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            font-size: 16px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        .btn-primary { background: #007cba; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-sm { padding: 5px 10px; font-size: 14px; }
        
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        
        .alert { padding: 15px; border-radius: 8px; margin: 15px 0; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .user-agent { 
            font-family: monospace; 
            font-size: 12px; 
            background: #f8f9fa; 
            padding: 5px; 
            border-radius: 3px;
            word-break: break-all;
        }
        
        .bot-signature { color: #007cba; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🤖 Управление ботами</h1>
            <div class="back-link">
                <a href="bot_monitor.php">← Назад к главной</a>
            </div>
        </div>
        
        <?php if (isset($message)): ?>
        <div class="alert alert-<?= $message_type ?>">
            <?= $message ?>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>➕ Добавить нового бота</h2>
            <form method="post">
                <input type="hidden" name="action" value="add_bot">
                
                <div class="form-group">
                    <label>Сигнатура бота (часть User-Agent для определения):</label>
                    <input type="text" name="bot_signature" placeholder="Например: MyBot или MyCrawler/1.0" required>
                    <small style="color: #6c757d;">Это текст, который должен содержаться в User-Agent для определения бота</small>
                </div>
                
                <div class="form-group">
                    <label>Название бота (для отображения в статистике):</label>
                    <input type="text" name="bot_name" placeholder="Например: My Custom Bot" required>
                </div>
                
                <button type="submit" class="btn btn-success">Добавить бота</button>
            </form>
        </div>
        
        <?php if ($custom_bots): ?>
        <div class="card">
            <h2>📋 Пользовательские боты (<?= count($custom_bots) ?>)</h2>
            <table>
                <thead>
                    <tr>
                        <th>Сигнатура</th>
                        <th>Название</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($custom_bots as $signature => $name): ?>
                    <tr>
                        <td><span class="bot-signature"><?= htmlspecialchars($signature) ?></span></td>
                        <td><?= htmlspecialchars($name) ?></td>
                        <td>
                            <a href="?action=manage_bots&delete=<?= urlencode($signature) ?>&confirm=yes" 
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('Удалить бота <?= htmlspecialchars($name) ?>?')">
                                Удалить
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if ($unknown_agents): ?>
        <div class="card">
            <h2>❓ Неопознанные User-Agent (последние 50)</h2>
            <p style="color: #6c757d;">Эти User-Agent были обнаружены, но не определены как боты. Вы можете добавить их вручную.</p>
            
            <table>
                <thead>
                    <tr>
                        <th>User-Agent</th>
                        <th>Кол-во визитов</th>
                        <th>Последний визит</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unknown_agents as $agent): ?>
                    <tr>
                        <td>
                            <div class="user-agent"><?= htmlspecialchars($agent['user_agent']) ?></div>
                        </td>
                        <td><?= $agent['visit_count'] ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($agent['last_visit'])) ?></td>
                        <td>
                            <button class="btn btn-primary btn-sm" 
                                    onclick="suggestBot('<?= htmlspecialchars($agent['user_agent'], ENT_QUOTES) ?>')">
                                Добавить как бота
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    function suggestBot(userAgent) {
        // Пытаемся извлечь название бота из User-Agent
        let botName = '';
        let signature = '';
        
        // Паттерны для извлечения названия
        const patterns = [
            /([A-Za-z0-9]+bot)/i,
            /([A-Za-z0-9]+spider)/i,
            /([A-Za-z0-9]+crawler)/i,
            /^([A-Za-z0-9\-\_]+)\//i,
            /\(([A-Za-z0-9\-\_]+);/i
        ];
        
        for (let pattern of patterns) {
            const match = userAgent.match(pattern);
            if (match) {
                signature = match[1];
                botName = signature.charAt(0).toUpperCase() + signature.slice(1);
                break;
            }
        }
        
        // Заполняем форму
        document.querySelector('input[name="bot_signature"]').value = signature || userAgent.substring(0, 50);
        document.querySelector('input[name="bot_name"]').value = botName || 'Unknown Bot';
        
        // Прокручиваем к форме
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    </script>
</body>
</html>
    <?php
}

private function get_unknown_user_agents() {
    // Получаем User-Agent которые не были определены как боты
    $unknown = $this->db_super_query("
        SELECT 
            user_agent,
            COUNT(*) as visit_count,
            MAX(visit_time) as last_visit
        FROM `{$this->table_name}`
        WHERE bot_name = 'Other Bot' OR bot_name IS NULL
        GROUP BY user_agent
        ORDER BY visit_count DESC
        LIMIT 50
    ", 1);
    
    return $unknown ?: [];
}
   
   private function install_tracker() {
       header('Content-Type: application/json; charset=utf-8');
       
       try {
           // Создаем файл трекера
           $tracker_code = '<?php
// Bot Monitor Tracker - автоматически отслеживает ботов
if (!defined("DATALIFEENGINE")) {
   return;
}

class BotTracker {
   private $db;
   private $table_name;
   
   public function __construct() {
       global $db;
       if (!$db) return;
       
       $this->db = $db;
       $this->table_name = PREFIX . "_bot_visits";
       
       // Отслеживаем только на фронтенде
       if (!defined("ADMIN_PANEL") && !$this->is_bot_page()) {
           $this->track_bot();
       }
   }
   
   private function is_bot_page() {
       $script = $_SERVER["SCRIPT_NAME"] ?? "";
       return strpos($script, "bot_monitor.php") !== false;
   }
   
   private function escape_string($value) {
       if (method_exists($this->db, "safesql")) {
           return $this->db->safesql($value);
       } elseif (method_exists($this->db, "escape")) {
           return $this->db->escape($value);
       } elseif (method_exists($this->db, "real_escape_string")) {
           return $this->db->real_escape_string($value);
       } else {
           return addslashes($value);
       }
   }
   
   private function db_query($query) {
       if (method_exists($this->db, "query")) {
           return $this->db->query($query);
       } elseif (method_exists($this->db, "super_query")) {
           return $this->db->super_query($query, false);
       } else {
           return false;
       }
   }
   
   private function track_bot() {
       $user_agent = $_SERVER["HTTP_USER_AGENT"] ?? "";
       if (empty($user_agent)) return;
       
       $bot_name = $this->identify_bot($user_agent);
       if ($bot_name) {
           $this->log_visit($bot_name, $user_agent);
       }
   }
   
   private function identify_bot($user_agent) {
    $bots = [
        "Googlebot" => "Google",
        "Google-InspectionTool" => "Google Inspector",
        "Googlebot-Image" => "Google Images", 
        "Googlebot-News" => "Google News",
        "bingbot" => "Bing",
        "BingPreview" => "Bing Preview",
        "YandexBot" => "Yandex",
        "YandexImages" => "Yandex Images",
        "Slurp" => "Yahoo",
        "DuckDuckBot" => "DuckDuckGo",
        "Baiduspider" => "Baidu",
        "facebookexternalhit" => "Facebook",
        "Facebot" => "Facebook Bot",
        "Twitterbot" => "Twitter",
        "LinkedInBot" => "LinkedIn",
        "TelegramBot" => "Telegram",
        "WhatsApp" => "WhatsApp",
        "SemrushBot" => "Semrush",
        "AhrefsBot" => "Ahrefs",
        "MJ12bot" => "Majestic",
        "DotBot" => "Moz",
        "SeznamBot" => "Seznam",
        "AppleBot" => "Apple"
    ];
    
    // Загружаем пользовательские боты
    $custom_bots_file = ENGINE_DIR . "/data/bot_monitor_custom_bots.json";
    if (file_exists($custom_bots_file)) {
        $custom_bots = json_decode(file_get_contents($custom_bots_file), true);
        if ($custom_bots) {
            $bots = array_merge($bots, $custom_bots);
        }
    }
    
    $ua_lower = strtolower($user_agent);
    
    foreach ($bots as $signature => $name) {
        if (strpos($ua_lower, strtolower($signature)) !== false) {
            return $name;
        }
    }
    
    // Общие паттерны ботов
    $patterns = ["bot", "crawler", "spider", "scraper"];
    foreach ($patterns as $pattern) {
        if (strpos($ua_lower, $pattern) !== false) {
            return "Other Bot";
        }
    }
    
    return false;
}
   
   private function log_visit($bot_name, $user_agent) {
       try {
           $ip = $this->get_real_ip();
           $url = $_SERVER["REQUEST_URI"] ?? "";
           $referer = $_SERVER["HTTP_REFERER"] ?? "";
           
           $this->db_query("INSERT INTO `{$this->table_name}` 
               (`bot_name`, `user_agent`, `ip_address`, `url`, `referer`, `visit_time`) 
               VALUES 
               (\'" . $this->escape_string($bot_name) . "\', 
                \'" . $this->escape_string(substr($user_agent, 0, 1000)) . "\', 
                \'" . $this->escape_string($ip) . "\', 
                \'" . $this->escape_string(substr($url, 0, 500)) . "\', 
                \'" . $this->escape_string(substr($referer, 0, 500)) . "\', 
                NOW())");
       } catch (Exception $e) {
           // Тихо игнорируем ошибки
       }
   }
   
   private function get_real_ip() {
       $headers = ["HTTP_CF_CONNECTING_IP", "HTTP_X_FORWARDED_FOR", "HTTP_X_FORWARDED", "REMOTE_ADDR"];
       foreach ($headers as $header) {
           if (!empty($_SERVER[$header])) {
               $ips = explode(",", $_SERVER[$header]);
               $ip = trim($ips[0]);
               if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                   return $ip;
               }
           }
       }
       return $_SERVER["REMOTE_ADDR"] ?? "unknown";
   }
}

// Автоматический запуск
new BotTracker();
?>';
           
           // Проверяем директорию
           $modules_dir = dirname(__FILE__) . '/engine/modules';
           if (!is_dir($modules_dir)) {
               if (!@mkdir($modules_dir, 0755, true)) {
                   throw new Exception('Не удалось создать директорию: ' . $modules_dir);
               }
           }
           
           // Сохраняем трекер
           $tracker_file = $modules_dir . '/bot_tracker.php';
           
           if (!@file_put_contents($tracker_file, $tracker_code)) {
               throw new Exception('Не удалось создать файл трекера. Проверьте права на запись в папку engine/modules/');
           }
           
           // Результат
           $result = [
               'success' => true,
               'message' => 'Трекер успешно установлен!',
               'tracker_path' => $tracker_file,
               'index_updated' => false,
               'engine_updated' => false,
               'dle_version' => $this->dle_version
           ];
           
           // Пытаемся добавить в оба файла
           $add_results = $this->add_to_both_files();
           if ($add_results['index']) {
               $result['index_updated'] = true;
           }
           if ($add_results['engine']) {
               $result['engine_updated'] = true;
           }
           
           if ($add_results['index'] && $add_results['engine']) {
               $result['message'] .= ' Автозапуск полностью настроен.';
           } elseif ($add_results['index'] || $add_results['engine']) {
               $result['message'] .= ' Автозапуск частично настроен. Проверьте инструкции для полной установки.';
           } else {
               $result['message'] .= ' Добавьте код подключения вручную (см. инструкцию ниже).';
           }
           
           echo json_encode($result);
           
       } catch (Exception $e) {
           echo json_encode([
               'success' => false,
               'message' => 'Ошибка: ' . $e->getMessage()
           ]);
       }
       
       exit;
   }
   
   private function add_to_both_files() {
       $results = [
           'index' => false,
           'engine' => false
       ];
       
       // Код для index.php
       $index_code = "\n\n// Bot Monitor Tracker\nif(file_exists(ENGINE_DIR . '/engine/modules/bot_tracker.php')) {\n    include_once ENGINE_DIR . '/engine/modules/bot_tracker.php';\n}";
       
       // Код для engine.php  
       $engine_code = "\n\n// Bot Monitor Tracker\nif(file_exists(ENGINE_DIR . '/modules/bot_tracker.php')) {\n    include_once ENGINE_DIR . '/modules/bot_tracker.php';\n}";
       
       // Добавляем в index.php
       $index_file = dirname(__FILE__) . '/index.php';
       if (file_exists($index_file) && is_writable($index_file)) {
           $content = file_get_contents($index_file);
           if (strpos($content, 'bot_tracker.php') === false) {
               $results['index'] = file_put_contents($index_file, $content . $index_code) !== false;
           } else {
               $results['index'] = true;
           }
       }
       
       // Добавляем в engine.php
       $engine_file = dirname(__FILE__) . '/engine/engine.php';
       if (file_exists($engine_file) && is_writable($engine_file)) {
           $content = file_get_contents($engine_file);
           if (strpos($content, 'bot_tracker.php') === false) {
               $results['engine'] = file_put_contents($engine_file, $content . $engine_code) !== false;
           } else {
               $results['engine'] = true;
           }
       }
       
       return $results;
   }
   
   private function uninstall_tracker() {
       if ($_POST['confirm'] ?? '' === 'yes') {
           header('Content-Type: application/json; charset=utf-8');
           
           $results = [
               'tracker_removed' => false,
               'index_cleaned' => false,
               'engine_cleaned' => false,
               'table_dropped' => false,
               'errors' => []
           ];
           
           // 1. Удаляем файл трекера
           $tracker_file = dirname(__FILE__) . '/engine/modules/bot_tracker.php';
           if (file_exists($tracker_file)) {
               if (@unlink($tracker_file)) {
                   $results['tracker_removed'] = true;
               } else {
                   $results['errors'][] = 'Не удалось удалить файл трекера';
               }
           } else {
               $results['tracker_removed'] = true; // Файла уже нет
           }
           
           // 2. Очищаем index.php
           $index_file = dirname(__FILE__) . '/index.php';
           if (file_exists($index_file) && is_writable($index_file)) {
               $content = file_get_contents($index_file);
               $patterns = [
                   '/\n*\/\/ Bot Monitor Tracker\s*\n\s*if\s*\(\s*file_exists\s*\(\s*ENGINE_DIR\s*\.\s*[\'"]\/engine\/modules\/bot_tracker\.php[\'"]\s*\)\s*\)\s*{\s*\n\s*include_once\s+ENGINE_DIR\s*\.\s*[\'"]\/engine\/modules\/bot_tracker\.php[\'"]\s*;\s*\n\s*}/i',
                   '/\n*\/\/ Bot Monitor Tracker.*?bot_tracker\.php[\'"]\s*;\s*\n\s*}/s'
               ];
               
               $cleaned = false;
               foreach ($patterns as $pattern) {
                   if (preg_match($pattern, $content)) {
                       $content = preg_replace($pattern, '', $content);
                       $cleaned = true;
                   }
               }
               
               if ($cleaned) {
                   if (file_put_contents($index_file, $content)) {
                       $results['index_cleaned'] = true;
                   } else {
                       $results['errors'][] = 'Не удалось обновить index.php';
                   }
               } else {
                   $results['index_cleaned'] = true; // Код уже удален
               }
           }
           
           // 3. Очищаем engine.php
           $engine_file = dirname(__FILE__) . '/engine/engine.php';
           if (file_exists($engine_file) && is_writable($engine_file)) {
               $content = file_get_contents($engine_file);
               $patterns = [
                   '/\n*\/\/ Bot Monitor Tracker\s*\n\s*if\s*\(\s*file_exists\s*\(\s*ENGINE_DIR\s*\.\s*[\'"]\/modules\/bot_tracker\.php[\'"]\s*\)\s*\)\s*{\s*\n\s*include_once\s+ENGINE_DIR\s*\.\s*[\'"]\/modules\/bot_tracker\.php[\'"]\s*;\s*\n\s*}/i',
                   '/\n*\/\/ Bot Monitor Tracker.*?bot_tracker\.php[\'"]\s*;\s*\n\s*}/s'
               ];
               
               $cleaned = false;
               foreach ($patterns as $pattern) {
                   if (preg_match($pattern, $content)) {
                       $content = preg_replace($pattern, '', $content);
                       $cleaned = true;
                   }
               }
               
               if ($cleaned) {
                   if (file_put_contents($engine_file, $content)) {
                       $results['engine_cleaned'] = true;
                   } else {
                       $results['errors'][] = 'Не удалось обновить engine.php';
                   }
               } else {
                   $results['engine_cleaned'] = true; // Код уже удален
               }
           }
           
           // 4. Опционально: удаляем таблицу из БД
           if ($_POST['drop_table'] ?? '' === 'yes') {
               try {
                   $this->db_query("DROP TABLE IF EXISTS `{$this->table_name}`");
                   $results['table_dropped'] = true;
               } catch (Exception $e) {
                   $results['errors'][] = 'Не удалось удалить таблицу из БД';
               }
           }
           
           // Формируем ответ
           $success = $results['tracker_removed'] && 
                      ($results['index_cleaned'] || $results['engine_cleaned']);
           
           $message = $success ? 'Деинсталляция завершена!' : 'Деинсталляция завершена с ошибками.';
           
           echo json_encode([
               'success' => $success,
               'message' => $message,
               'details' => $results
           ]);
           exit;
       }
       
       // Показываем страницу подтверждения
       ?>
<!DOCTYPE html>
<html lang="ru">
<head>
   <meta charset="utf-8">
   <title>Удаление Bot Monitor</title>
   <style>
       body { 
           font-family: system-ui, sans-serif; 
           background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
           padding: 50px; 
           min-height: 100vh;
           margin: 0;
       }
       .container { 
           max-width: 600px; 
           margin: 0 auto; 
           background: rgba(255,255,255,0.95); 
           padding: 40px; 
           border-radius: 20px; 
           box-shadow: 0 20px 40px rgba(0,0,0,0.1);
       }
       h1 { color: #dc3545; margin-bottom: 30px; }
       .warning { 
           background: #f8d7da; 
           border: 1px solid #f5c6cb; 
           padding: 20px; 
           border-radius: 10px; 
           margin: 20px 0; 
           color: #721c24; 
       }
       .checklist { 
           background: #f8f9fa; 
           padding: 20px; 
           border-radius: 10px; 
           margin: 20px 0; 
       }
       .checklist h3 { margin-top: 0; color: #495057; }
       .checklist ul { margin: 10px 0; padding-left: 25px; }
       .checklist li { margin: 8px 0; }
       .checkbox-group { 
           margin: 20px 0; 
           padding: 15px; 
           background: #e9ecef; 
           border-radius: 8px;
       }
       .checkbox-group label { 
           display: flex; 
           align-items: center; 
           cursor: pointer; 
           font-weight: 500;
       }
       .checkbox-group input[type="checkbox"] { 
           margin-right: 10px; 
           width: 20px; 
           height: 20px; 
           cursor: pointer;
       }
       .btn { 
           padding: 12px 24px; 
           margin: 10px 5px; 
           border: none; 
           border-radius: 8px; 
           cursor: pointer; 
           text-decoration: none; 
           display: inline-block; 
           font-weight: 500;
           transition: all 0.3s ease;
       }
       .btn-danger { background: #dc3545; color: white; }
       .btn-secondary { background: #6c757d; color: white; }
       .btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
       .loading { display: none; text-align: center; margin: 20px 0; }
       .result { margin: 20px 0; padding: 15px; border-radius: 8px; display: none; }
       .result-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
       .result-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
   </style>
</head>
<body>
   <div class="container">
       <h1>🗑️ Удаление Bot Monitor</h1>
       
       <div class="warning">
           <strong>⚠️ Внимание!</strong><br>
           Вы собираетесь полностью удалить Bot Monitor с вашего сайта.
       </div>
       
       <div class="checklist">
           <h3>Что будет удалено:</h3>
           <ul>
               <li>✓ Файл трекера <code>/engine/modules/bot_tracker.php</code></li>
               <li>✓ Код подключения из <code>index.php</code></li>
               <li>✓ Код подключения из <code>engine.php</code></li>
               <li>✓ Таблица из базы данных (опционально)</li>
           </ul>
       </div>
       
       <form id="uninstall-form" onsubmit="return performUninstall(event);">
           <div class="checkbox-group">
               <label>
                   <input type="checkbox" name="drop_table" id="drop_table" value="yes">
                   <span>Удалить таблицу <code><?= $this->table_name ?></code> из базы данных<br>
                   <small style="color: #6c757d; margin-left: 30px;">Все данные о посещениях ботов будут удалены безвозвратно</small></span>
               </label>
           </div>
           
           <div style="text-align: center; margin-top: 30px;">
               <input type="hidden" name="confirm" value="yes">
               <button type="submit" class="btn btn-danger">Удалить Bot Monitor</button>
               <a href="bot_monitor.php" class="btn btn-secondary">Отмена</a>
           </div>
       </form>
       
       <div id="loading" class="loading">
           <p>⏳ Выполняется удаление...</p>
       </div>
       
       <div id="result" class="result"></div>
   </div>
   
   <script>
   function performUninstall(event) {
       event.preventDefault();
       
       if (!confirm('Вы уверены, что хотите удалить Bot Monitor?')) {
           return false;
       }
       
       document.getElementById('loading').style.display = 'block';
       document.getElementById('result').style.display = 'none';
       
       const formData = new FormData();
       formData.append('confirm', 'yes');
       if (document.getElementById('drop_table').checked) {
           formData.append('drop_table', 'yes');
       }
       
       fetch('bot_monitor.php?action=uninstall', {
           method: 'POST',
           body: formData
       })
       .then(response => response.json())
       .then(data => {
           document.getElementById('loading').style.display = 'none';
           const resultDiv = document.getElementById('result');
           
           if (data.success) {
               resultDiv.className = 'result result-success';
               resultDiv.innerHTML = '<strong>✅ ' + data.message + '</strong>';
               
               if (data.details) {
                   let details = '<ul style="margin-top: 10px;">';
                   if (data.details.tracker_removed) details += '<li>Файл трекера удален</li>';
                   if (data.details.index_cleaned) details += '<li>index.php очищен</li>';
                   if (data.details.engine_cleaned) details += '<li>engine.php очищен</li>';
                   if (data.details.table_dropped) details += '<li>Таблица удалена из БД</li>';
                   details += '</ul>';
                   resultDiv.innerHTML += details;
               }
               
               resultDiv.innerHTML += '<p style="margin-top: 15px;">Файл <code>bot_monitor.php</code> нужно удалить вручную.</p>';
               resultDiv.innerHTML += '<p><a href="/" class="btn btn-secondary">Перейти на главную</a></p>';
           } else {
               resultDiv.className = 'result result-error';
               resultDiv.innerHTML = '<strong>❌ ' + data.message + '</strong>';
               
               if (data.details && data.details.errors) {
                   resultDiv.innerHTML += '<ul>';
                   data.details.errors.forEach(error => {
                       resultDiv.innerHTML += '<li>' + error + '</li>';
                   });
                   resultDiv.innerHTML += '</ul>';
               }
           }
           
           resultDiv.style.display = 'block';
       })
       .catch(error => {
           document.getElementById('loading').style.display = 'none';
           const resultDiv = document.getElementById('result');
           resultDiv.className = 'result result-error';
           resultDiv.innerHTML = '<strong>❌ Ошибка:</strong> ' + error;
           resultDiv.style.display = 'block';
       });
       
       return false;
   }
   </script>
</body>
</html>
       <?php
       exit;
   }
   
   private function show_main_page() {
       $stats = $this->get_basic_stats();
       $is_tracker_installed = file_exists('engine/modules/bot_tracker.php');
       $tracker_in_index = false;
       
       if (file_exists('index.php')) {
           $index_content = file_get_contents('index.php');
           $tracker_in_index = strpos($index_content, 'bot_tracker.php') !== false;
       }
       
       ?>
<!DOCTYPE html>
<html lang="ru">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <title>Bot Monitor - Автономный мониторинг ботов</title>
   <style>
       * { box-sizing: border-box; }
       body { 
           font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
           margin: 0; 
           background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
           min-height: 100vh;
           color: #333;
       }
       .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
       .header { text-align: center; color: white; margin-bottom: 40px; }
       .header h1 { font-size: 3.5em; margin: 0; text-shadow: 0 4px 8px rgba(0,0,0,0.3); }
       .header p { font-size: 1.2em; opacity: 0.9; margin: 10px 0; }
       
       .card { 
           background: rgba(255,255,255,0.95); 
           border-radius: 20px; 
           padding: 30px; 
           margin: 20px 0; 
           box-shadow: 0 20px 40px rgba(0,0,0,0.1);
           backdrop-filter: blur(10px);
       }
       
       .stats-grid { 
           display: grid; 
           grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
           gap: 20px; 
           margin: 30px 0; 
       }
       
       .stat-card { 
           background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); 
           padding: 25px; 
           border-radius: 15px; 
           text-align: center;
           border: 1px solid rgba(0,0,0,0.05);
       }
       
       .stat-number { 
           font-size: 2.5em; 
           font-weight: 700; 
           color: #007cba; 
           margin-bottom: 8px;
       }
       
       .stat-label { color: #6c757d; font-weight: 500; }
       
       .status-indicator {
           display: inline-flex;
           align-items: center;
           gap: 8px;
           padding: 8px 16px;
           border-radius: 20px;
           font-weight: 500;
           margin: 5px;
       }
       
       .status-ok { background: #d4edda; color: #155724; }
       .status-warning { background: #fff3cd; color: #856404; }
       .status-error { background: #f8d7da; color: #721c24; }
       
       .btn {
           display: inline-block;
           padding: 12px 24px;
           margin: 8px;
           border: none;
           border-radius: 8px;
           text-decoration: none;
           font-weight: 500;
           cursor: pointer;
           transition: all 0.3s ease;
       }
       
       .btn-primary { background: #007cba; color: white; }
       .btn-success { background: #28a745; color: white; }
       .btn-warning { background: #ffc107; color: #212529; }
       .btn-danger { background: #dc3545; color: white; }
       .btn-secondary { background: #6c757d; color: white; }
       
       .btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
       
       .installation-steps {
           background: #e6f3ff;
           border-left: 4px solid #007cba;
           padding: 20px;
           border-radius: 0 10px 10px 0;
           margin: 20px 0;
       }
       
       .step { margin: 15px 0; padding: 10px 0; }
       .step-number { 
           display: inline-block; 
           width: 30px; 
           height: 30px; 
           background: #007cba; 
           color: white; 
           border-radius: 50%; 
           text-align: center; 
           line-height: 30px; 
           font-weight: bold; 
           margin-right: 10px;
       }
       
       .features { 
           display: grid; 
           grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
           gap: 20px; 
           margin: 30px 0; 
       }
       
       .feature { 
           padding: 20px; 
           background: #f8f9fa; 
           border-radius: 10px; 
           border-left: 4px solid #007cba; 
       }
       
       .loading { display: none; }
       .alert { padding: 15px; border-radius: 8px; margin: 15px 0; }
       .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
       .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
       
       details summary::-webkit-details-marker { color: #007cba; }
       details[open] summary { margin-bottom: 10px; color: #007cba; }
       pre { white-space: pre-wrap; word-wrap: break-word; }
       code { font-family: 'Courier New', Courier, monospace; font-size: 0.9em; }
       
       @media (max-width: 768px) {
           .header h1 { font-size: 2.5em; }
           .container { padding: 10px; }
           .card { padding: 20px; }
       }
   </style>
</head>
<body>
   <div class="container">
       <div class="header">
           <h1>🤖 Bot Monitor</h1>
           <p>Универсальный мониторинг поисковых ботов для DLE <?= $this->dle_version ?>+</p>
           <p>Независимый модуль без интеграции в админку</p>
       </div>
       
       <div class="card">
           <h2>📊 Текущая статистика</h2>
           <div class="stats-grid">
               <div class="stat-card">
                   <div class="stat-number"><?= number_format($stats['total']) ?></div>
                   <div class="stat-label">Всего посещений ботов</div>
               </div>
               <div class="stat-card">
                   <div class="stat-number"><?= number_format($stats['today']) ?></div>
                   <div class="stat-label">Сегодня</div>
               </div>
               <div class="stat-card">
                   <div class="stat-number"><?= $stats['unique_bots'] ?></div>
                   <div class="stat-label">Уникальных ботов</div>
               </div>
               <div class="stat-card">
                   <div class="stat-number"><?= $stats['this_week'] ?></div>
                   <div class="stat-label">За неделю</div>
               </div>
           </div>
       </div>
       
       <div class="card">
           <h2>⚙️ Статус установки</h2>
           
           <div style="margin: 20px 0;">
               <span class="status-indicator status-ok">
                   ✅ База данных подключена
               </span>
               <span class="status-indicator status-ok">
                   ✅ DLE версия <?= $this->dle_version ?>
               </span>
               <span class="status-indicator <?= $is_tracker_installed ? 'status-ok' : 'status-warning' ?>">
                   <?= $is_tracker_installed ? '✅' : '⚠️' ?> Трекер <?= $is_tracker_installed ? 'установлен' : 'не установлен' ?>
               </span>
               <span class="status-indicator <?= $tracker_in_index ? 'status-ok' : 'status-warning' ?>">
                   <?= $tracker_in_index ? '✅' : '⚠️' ?> Автозапуск <?= $tracker_in_index ? 'настроен' : 'не настроен' ?>
               </span>
           </div>
           
           <?php if (!$is_tracker_installed || !$tracker_in_index): ?>
           <div class="installation-steps">
               <h4>🚀 Быстрая установка трекера:</h4>
               <div class="step">
                   <span class="step-number">1</span>
                   Нажмите кнопку "Установить трекер" ниже
               </div>
               <div class="step">
                   <span class="step-number">2</span>
                   Трекер автоматически создастся и подключится к DLE
               </div>
               <div class="step">
                   <span class="step-number">3</span>
                   Готово! Боты будут отслеживаться автоматически
               </div>
               
               <button onclick="installTracker()" class="btn btn-success">
                   🔧 Установить трекер автоматически
               </button>
               
               <div id="install-loading" class="loading" style="display: none;">
                   <p>⏳ Установка трекера...</p>
               </div>
               <div id="install-result"></div>
               
               <!-- Блок с инструкцией для ручной установки -->
               <details style="margin-top: 20px; background: #f8f9fa; padding: 15px; border-radius: 8px;">
                   <summary style="cursor: pointer; font-weight: bold; color: #495057;">
                       📝 Ручная установка (если автоматическая не сработала)
                   </summary>
                   <div style="margin-top: 15px;">
                       <p><strong>Если автоматическая установка не удалась, добавьте следующий код вручную:</strong></p>
                       
                       <p>1️⃣ В <strong>КОНЕЦ</strong> файла <code>/index.php</code>:</p>
                       <pre style="background: #e9ecef; padding: 10px; border-radius: 5px; overflow-x: auto;"><code>// Bot Monitor Tracker
if(file_exists(ENGINE_DIR . '/engine/modules/bot_tracker.php')) {
   include_once ENGINE_DIR . '/engine/modules/bot_tracker.php';
}</code></pre>
                       
                       <p>2️⃣ В <strong>КОНЕЦ</strong> файла <code>/engine/engine.php</code>:</p>
                       <pre style="background: #e9ecef; padding: 10px; border-radius: 5px; overflow-x: auto;"><code>// Bot Monitor Tracker
if(file_exists(ENGINE_DIR . '/modules/bot_tracker.php')) {
   include_once ENGINE_DIR . '/modules/bot_tracker.php';
}</code></pre>
                       
                       <div style="background: #e7f3ff; padding: 15px; border-radius: 5px; margin-top: 15px;">
                           <p style="margin: 0; color: #004085;">
                               <strong>⚠️ Обратите внимание на разницу в путях:</strong>
                           </p>
                           <ul style="margin: 10px 0 0 20px; color: #004085;">
                               <li>В <code>index.php</code>: <code>ENGINE_DIR . '/engine/modules/bot_tracker.php'</code></li>
                               <li>В <code>engine.php</code>: <code>ENGINE_DIR . '/modules/bot_tracker.php'</code></li>
                               <li>Это важно, так как ENGINE_DIR определяется по-разному в этих файлах</li>
                           </ul>
                       </div>
                       
                       <p style="color: #6c757d; font-size: 0.9em; margin-top: 15px;">
                           💡 <strong>После добавления кода обновите эту страницу - статус должен измениться на "✅ Автозапуск настроен"</strong>
                       </p>
                   </div>
               </details>
           </div>
           <?php endif; ?>
       </div>
       
       <div class="card">
           <h2>📈 Управление данными</h2>
           <div style="text-align: center;">
               <a href="?action=stats" class="btn btn-primary">📊 Подробная статистика</a>
			   <a href="?action=bot_load" class="btn btn-warning">🚀 Анализ нагрузки ботов</a>
			   <a href="?action=manage_bots" class="btn btn-success">🤖 Управление ботами</a>
               <a href="?action=api&format=json" class="btn btn-secondary">📋 API (JSON)</a>
               <a href="?action=export&format=csv" class="btn btn-warning">📤 Экспорт CSV</a>
               
               <?php if ($stats['total'] > 0): ?>
               <button onclick="clearData()" class="btn btn-danger">🗑️ Очистить данные</button>
               <?php endif; ?>
               
               <?php if ($is_tracker_installed): ?>
               <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dee2e6;">
                   <a href="?action=uninstall" class="btn btn-danger" style="background: #6c757d;">
                       🔧 Деинсталлировать Bot Monitor
                   </a>
               </div>
               <?php endif; ?>
           </div>
       </div>
       
       <div class="card">
           <h2>✨ Возможности Bot Monitor</h2>
           <div class="features">
               <div class="feature">
                   <h4>🤖 Автоматическое отслеживание</h4>
                   <p>Определяет и записывает посещения Google, Yandex, Bing, Facebook, Twitter и других ботов без настройки</p>
               </div>
               <div class="feature">
                   <h4>📊 Детальная статистика</h4>
                   <p>Графики активности, топ ботов, статистика по дням и IP адресам</p>
               </div>
               <div class="feature">
                   <h4>🔗 Универсальная совместимость</h4>
                   <p>Работает со всеми версиями DLE начиная с 15.2 и выше</p>
               </div>
               <div class="feature">
                   <h4>📱 Адаптивный дизайн</h4>
                   <p>Красивый интерфейс, который отлично работает на любых устройствах</p>
               </div>
               <div class="feature">
                   <h4>⚡ Высокая производительность</h4>
                   <p>Минимальное влияние на скорость сайта, эффективные SQL запросы</p>
               </div>
               <div class="feature">
                   <h4>📤 Экспорт данных</h4>
                   <p>Выгрузка статистики в CSV, JSON для анализа в Excel или других программах</p>
               </div>
           </div>
       </div>
       
       <div class="card" style="text-align: center; color: #6c757d;">
           <p>Bot Monitor v3.0 Universal | Совместим с DLE <?= $this->dle_version ?>+</p>
           <p><small>Для удаления используйте встроенный деинсталлятор или удалите файлы вручную</small></p>
       </div>
   </div>
   
   <script>
   function installTracker() {
       document.getElementById('install-loading').style.display = 'block';
       document.getElementById('install-result').innerHTML = '';
       
       fetch('bot_monitor.php?action=install_tracker', {
           method: 'GET',
           headers: {
               'X-Requested-With': 'XMLHttpRequest'
           }
       })
       .then(response => {
           if (!response.ok) {
               throw new Error('HTTP error! status: ' + response.status);
           }
           return response.text();
       })
       .then(text => {
           try {
               const data = JSON.parse(text);
               document.getElementById('install-loading').style.display = 'none';
               
               const resultDiv = document.getElementById('install-result');
               if (data.success) {
                   resultDiv.innerHTML = '<div class="alert alert-success">✅ ' + data.message + ' Перезагрузка через 2 секунды...</div>';
                   setTimeout(() => location.reload(), 2000);
               } else {
                   resultDiv.innerHTML = '<div class="alert alert-error">❌ ' + data.message + '</div>';
               }
           } catch (e) {
               throw new Error('Ошибка парсинга JSON: ' + e.message + '\nОтвет сервера: ' + text);
           }
       })
       .catch(error => {
           document.getElementById('install-loading').style.display = 'none';
           document.getElementById('install-result').innerHTML = '<div class="alert alert-error">❌ Ошибка: ' + error.message + '</div>';
           console.error('Ошибка установки:', error);
       });
   }
   
   function clearData() {
       if (confirm('Вы уверены? Все данные о посещениях ботов будут удалены!')) {
           window.location.href = 'bot_monitor.php?action=clear';
       }
   }
   </script>
</body>
</html>
       <?php
   }
   
   private function get_basic_stats() {
       $total = $this->db_super_query("SELECT COUNT(*) as count FROM `{$this->table_name}`");
       $today = $this->db_super_query("SELECT COUNT(*) as count FROM `{$this->table_name}` WHERE DATE(visit_time) = CURDATE()");
       $unique_bots = $this->db_super_query("SELECT COUNT(DISTINCT bot_name) as count FROM `{$this->table_name}`");
       $this_week = $this->db_super_query("SELECT COUNT(*) as count FROM `{$this->table_name}` WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
       
       return [
           'total' => $total['count'] ?? 0,
           'today' => $today['count'] ?? 0,
           'unique_bots' => $unique_bots['count'] ?? 0,
           'this_week' => $this_week['count'] ?? 0
       ];
   }
   
   private function show_stats_page() {
       $period = $_GET['period'] ?? '7';
       $period = in_array($period, ['1', '7', '30', '90']) ? $period : '7';
       
       $stats = $this->get_detailed_stats($period);
       
       ?>
<!DOCTYPE html>
<html lang="ru">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <title>Bot Monitor - Подробная статистика</title>
   <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
   <style>
       body { 
           font-family: system-ui, sans-serif; 
           margin: 0; 
           background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
           min-height: 100vh; 
       }
       .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
       .header { text-align: center; color: white; margin-bottom: 30px; }
       .card { 
           background: rgba(255,255,255,0.95); 
           border-radius: 15px; 
           padding: 25px; 
           margin: 20px 0; 
           box-shadow: 0 10px 30px rgba(0,0,0,0.1); 
       }
       .controls { text-align: center; margin: 20px 0; }
       .controls select, .controls a { 
           padding: 8px 16px; 
           margin: 0 5px; 
           border-radius: 6px; 
           text-decoration: none; 
       }
       .btn { 
           background: #007cba; 
           color: white; 
           border: none; 
           padding: 10px 20px; 
           border-radius: 6px; 
           cursor: pointer; 
       }
       .chart-container { height: 400px; margin: 20px 0; }
       table { width: 100%; border-collapse: collapse; margin: 20px 0; }
       th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
       th { background: #f8f9fa; font-weight: 600; }
       tr:hover { background: #f8f9fa; }
       .back-link { text-align: center; margin: 20px 0; }
       .back-link a { color: white; text-decoration: none; padding: 10px 20px; background: rgba(255,255,255,0.2); border-radius: 6px; }
	   table a {
    color: #007cba;
    text-decoration: none;
    transition: color 0.3s ease;
}

table a:hover {
    color: #0056b3;
    text-decoration: underline;
}

table a code {
    background: #f8f9fa;
    padding: 2px 4px;
    border-radius: 3px;
}

table a:hover code {
    background: #e9ecef;
}
   </style>
</head>
<body>
   <div class="container">
       <div class="header">
           <h1>📊 Подробная статистика Bot Monitor</h1>
           <div class="back-link">
               <a href="bot_monitor.php">← Назад к главной</a>
           </div>
       </div>
       
       <div class="card">
           <div class="controls">
               <label>Период: </label>
               <select onchange="changePeriod(this.value)">
                   <option value="1" <?= $period == '1' ? 'selected' : '' ?>>Последний день</option>
                   <option value="7" <?= $period == '7' ? 'selected' : '' ?>>Последняя неделя</option>
                   <option value="30" <?= $period == '30' ? 'selected' : '' ?>>Последний месяц</option>
                   <option value="90" <?= $period == '90' ? 'selected' : '' ?>>Последние 3 месяца</option>
               </select>
           </div>
           
           <div class="chart-container">
               <canvas id="visitsChart"></canvas>
           </div>
       </div>
       
       <div class="card">
           <h3>🏆 Топ поисковых ботов (за <?= $period ?> дн.)</h3>
           <table>
               <thead>
                   <tr>
                       <th>Поисковый бот</th>
                       <th>Посещений</th>
                       <th>Последнее посещение</th>
                       <th>Процент</th>
                   </tr>
               </thead>
               <tbody>
                   <?php if ($stats['top_bots']): ?>
                       <?php 
                       $total = array_sum(array_column($stats['top_bots'], 'count'));
                       foreach ($stats['top_bots'] as $bot): 
                           $percent = $total > 0 ? round(($bot['count'] / $total) * 100, 1) : 0;
                       ?>
                       <tr>
                           <td><strong><?= htmlspecialchars($bot['bot_name']) ?></strong></td>
                           <td><?= number_format($bot['count']) ?></td>
                           <td><?= date('d.m.Y H:i', strtotime($bot['last_visit'])) ?></td>
                           <td><span style="color: #007cba; font-weight: bold;"><?= $percent ?>%</span></td>
                       </tr>
                       <?php endforeach; ?>
                   <?php else: ?>
                       <tr><td colspan="4" style="text-align: center; color: #6c757d;">Нет данных за выбранный период</td></tr>
                   <?php endif; ?>
               </tbody>
           </table>
       </div>
       
       <div class="card">
    <h3>📄 Топ индексируемых страниц</h3>
    <table>
        <thead>
            <tr>
                <th>URL</th>
                <th>Посещений ботами</th>
                <th>Уникальных ботов</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($stats['top_pages']): ?>
                <?php 
                // Получаем базовый URL сайта
                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
                
                foreach ($stats['top_pages'] as $page): 
                    // Формируем полный URL
                    $full_url = $page['url'];
                    if (strpos($full_url, 'http') !== 0) {
                        // Если URL относительный, добавляем домен
                        $full_url = $base_url . $full_url;
                    }
                    
                    // Текст для отображения (обрезанный)
                    $display_url = htmlspecialchars($page['url']);
                    $is_truncated = strlen($page['url']) > 80;
                    if ($is_truncated) {
                        $display_url = htmlspecialchars(substr($page['url'], 0, 80)) . '...';
                    }
                ?>
                <tr>
                    <td>
                        <a href="<?= htmlspecialchars($full_url) ?>" 
                           target="_blank" 
                           rel="noopener noreferrer"
                           style="text-decoration: none; color: #007cba;"
                           title="<?= htmlspecialchars($page['url']) ?>">
                            <code style="color: inherit;"><?= $display_url ?></code>
                        </a>
                    </td>
                    <td><?= $page['visits'] ?></td>
                    <td><?= $page['unique_bots'] ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3" style="text-align: center; color: #6c757d;">Нет данных</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
   </div>
   
   <script>
   function changePeriod(period) {
       window.location.href = '?action=stats&period=' + period;
   }
   
   // График посещений
   const ctx = document.getElementById('visitsChart').getContext('2d');
   const chartData = <?= json_encode($stats['chart_data']) ?>;
   
   // Группируем данные по ботам
   const botData = {};
   const dates = [...new Set(chartData.map(item => item.date))].sort();
   
   chartData.forEach(item => {
       if (!botData[item.bot_name]) {
           botData[item.bot_name] = {};
       }
       botData[item.bot_name][item.date] = parseInt(item.visits);
   });
   
   const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#FF8C00', '#32CD32'];
   const datasets = Object.keys(botData).map((bot, index) => ({
       label: bot,
       data: dates.map(date => botData[bot][date] || 0),
       borderColor: colors[index % colors.length],
       backgroundColor: colors[index % colors.length] + '20',
       fill: false,
       tension: 0.1
   }));
   
   new Chart(ctx, {
       type: 'line',
       data: {
           labels: dates,
           datasets: datasets
       },
       options: {
           responsive: true,
           maintainAspectRatio: false,
           plugins: {
               title: {
                   display: true,
                   text: 'Активность ботов по дням'
               },
               legend: {
                   position: 'top'
               }
           },
           scales: {
               y: {
                   beginAtZero: true,
                   title: {
                       display: true,
                       text: 'Количество посещений'
                   }
               },
               x: {
                   title: {
                       display: true,
                       text: 'Дата'
                   }
               }
           }
       }
   });
   </script>
</body>
</html>
       <?php
   }
   
   private function get_detailed_stats($period) {
       // График данных
       $chart_data = $this->db_super_query("
           SELECT 
               bot_name,
               DATE(visit_time) as date,
               COUNT(*) as visits
           FROM `{$this->table_name}` 
           WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
           GROUP BY bot_name, DATE(visit_time)
           ORDER BY date ASC
       ", 1);
       
       // Топ ботов
       $top_bots = $this->db_super_query("
           SELECT 
               bot_name,
               COUNT(*) as count,
               MAX(visit_time) as last_visit
           FROM `{$this->table_name}` 
           WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
           GROUP BY bot_name
           ORDER BY count DESC
           LIMIT 15
       ", 1);
       
       // Топ страниц
       $top_pages = $this->db_super_query("
           SELECT 
               url,
               COUNT(*) as visits,
               COUNT(DISTINCT bot_name) as unique_bots
           FROM `{$this->table_name}` 
           WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
           GROUP BY url
           ORDER BY visits DESC
           LIMIT 20
       ", 1);
       
       return [
           'chart_data' => $chart_data ?: [],
           'top_bots' => $top_bots ?: [],
           'top_pages' => $top_pages ?: []
       ];
   }
   
   private function api_endpoint() {
       header('Content-Type: application/json; charset=utf-8');
       header('Access-Control-Allow-Origin: *');
       
       $period = $_GET['period'] ?? '7';
       $format = $_GET['format'] ?? 'json';
       
       $data = [
           'basic_stats' => $this->get_basic_stats(),
           'detailed_stats' => $this->get_detailed_stats($period),
           'generated_at' => date('Y-m-d H:i:s'),
           'period_days' => $period,
           'dle_version' => $this->dle_version
       ];
       
       echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
       exit;
   }
   
   private function export_data() {
       $format = $_GET['format'] ?? 'csv';
       $period = $_GET['period'] ?? '30';
       
       $data = $this->db_super_query("
           SELECT 
               bot_name,
               user_agent,
               ip_address,
               url,
               referer,
               visit_time
           FROM `{$this->table_name}` 
           WHERE visit_time >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
           ORDER BY visit_time DESC
       ", 1);
       
       if ($format == 'csv') {
           header('Content-Type: text/csv; charset=utf-8');
           header('Content-Disposition: attachment; filename="bot_visits_' . date('Y-m-d') . '.csv"');
           
           $output = fopen('php://output', 'w');
           
           // BOM для правильного отображения в Excel
           fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
           
           // Заголовки
           fputcsv($output, ['Бот', 'User Agent', 'IP адрес', 'URL', 'Реферер', 'Время посещения'], ';');
           
           // Данные
           if ($data) {
               foreach ($data as $row) {
                   fputcsv($output, [
                       $row['bot_name'],
                       $row['user_agent'],
                       $row['ip_address'], 
                       $row['url'],
                       $row['referer'],
                       $row['visit_time']
                   ], ';');
               }
           }
           
           fclose($output);
       } else {
           header('Content-Type: application/json; charset=utf-8');
           header('Content-Disposition: attachment; filename="bot_visits_' . date('Y-m-d') . '.json"');
           
           echo json_encode($data ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
       }
       exit;
   }
   
   private function clear_data() {
       if ($_POST['confirm'] ?? '' === 'yes') {
           $this->db_query("TRUNCATE TABLE `{$this->table_name}`");
           header('Location: bot_monitor.php?cleared=1');
           exit;
       }
       
       ?>
<!DOCTYPE html>
<html lang="ru">
<head>
   <meta charset="utf-8">
   <title>Очистка данных - Bot Monitor</title>
   <style>
       body { font-family: system-ui, sans-serif; background: #f5f5f5; padding: 50px; }
       .container { max-width: 500px; margin: 0 auto; background: white; padding: 40px; border-radius: 10px; text-align: center; }
       .btn { padding: 12px 24px; margin: 10px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
       .btn-danger { background: #dc3545; color: white; }
       .btn-secondary { background: #6c757d; color: white; }
       .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; border-radius: 6px; margin: 20px 0; color: #856404; }
   </style>
</head>
<body>
   <div class="container">
       <h1>🗑️ Очистка данных</h1>
       
       <div class="warning">
           <strong>⚠️ Внимание!</strong><br>
           Все данные о посещениях ботов будут безвозвратно удалены.
           Это действие нельзя отменить!
       </div>
       
       <form method="post">
           <input type="hidden" name="confirm" value="yes">
           <button type="submit" class="btn btn-danger">Да, удалить все данные</button>
       </form>
       
       <a href="bot_monitor.php" class="btn btn-secondary">Отмена</a>
   </div>
</body>
</html>
       <?php
       exit;
   }
}

// Проверка на очистку данных
if (isset($_GET['cleared'])) {
   ?>
<!DOCTYPE html>
<html lang="ru">
<head>
   <meta charset="utf-8">
   <title>Данные очищены</title>
   <style>
       body { font-family: system-ui, sans-serif; background: #f5f5f5; padding: 50px; text-align: center; }
       .success { color: #28a745; font-size: 4em; }
   </style>
</head>
<body>
   <div class="success">✅</div>
   <h1>Данные успешно очищены</h1>
   <p><a href="bot_monitor.php">Вернуться к Bot Monitor</a></p>
</body>
</html>
   <?php
   exit;
}

// Запуск приложения
new UniversalBotMonitor();
?>