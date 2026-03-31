<?php


session_start();

require_once __DIR__ . '/vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Twig\TwigFilter;


$host = 'localhost';
$db = 'system_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Erro ao conectar no MySQL: " . $e->getMessage());
}


$loader = new FilesystemLoader(__DIR__ . '/templates');
$twig = new Environment($loader, ['cache' => false, 'debug' => true]);


$twig->addGlobal('funcao', new class {
    public $id = 1;
    public function isLideranca()
    {
        return true;
    }
});
$twig->addFunction(new TwigFunction('permission', function ($p) {
    return true;
}));
$twig->addFunction(new TwigFunction('token', function () {
    return 'token_' . bin2hex(random_bytes(4));
}));
$twig->addFunction(new TwigFunction('error', function ($c) {
    return "Erro $c";
}));
$twig->addFunction(new TwigFunction('coluna', function ($n) {
    $m = [4 => 'four', 8 => 'eight', 12 => 'twelve', 16 => 'sixteen'];
    return $m[$n] ?? 'eight';
}));
$twig->addFunction(new TwigFunction('selected', function ($v1, $v2) {
    return ($v1 == $v2) ? 'selected' : '';
}));
$twig->addGlobal('alert', new class {
    public function show()
    {
        if (isset($_SESSION['flash_msg'])) {
            $msg = $_SESSION['flash_msg'];
            unset($_SESSION['flash_msg']);
            $color = (strpos($msg, 'Erro') !== false || strpos($msg, 'remover') !== false) ? 'red' : 'green';
            return "<div class='ui message $color'>$msg</div>";
        }
        return '';
    }
});
$twig->addFilter(new TwigFilter('to_array', function ($v) {
    return is_array($v) ? $v : explode(',', $v);
}));


$route = $_GET['route'] ?? 'pendencia';
$parts = explode('/', trim($route, '/'));
$action = $parts[1] ?? 'index';
$id = (int) ($parts[2] ?? 0);


$filtro = array_merge(['status' => '-1', 'q' => ''], $_GET['filtro'] ?? []);
$limit = 12;
$pagina_atual = (int) ($_GET['pagina'] ?? 1);
if ($pagina_atual < 1)
    $pagina_atual = 1;
$offset = ($pagina_atual - 1) * $limit;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['ajax_action'], $_POST['id'])) {
        header('Content-Type: application/json');
        $new_status = ($_POST['ajax_action'] === 'pago') ? 2 : 0;
        $stmt = $pdo->prepare("UPDATE pendencias SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, (int) $_POST['id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    
        if ($id > 0 && $action === 'apagar') {
            
            $stmt = $pdo->prepare("DELETE FROM pendencias WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['flash_msg'] = "Registro #$id removido permanentemente!";
        } elseif (isset($_POST['_token'])) {
            $data = [
                'aplicador' => $_POST['aplicador'] ?? 'Arquiteto',
                'responsavel_oc' => $_POST['oc'] ?? '',
                'envolvidos' => $_POST['envolvidos'] ?? '',
                'valor' => $_POST['valor'] ?? 0,
                'horario' => $_POST['horario'] ?? '00:00'
            ];

            if ($id > 0) {
                
                $status = (int) ($_POST['status'] ?? 1);
                $stmt = $pdo->prepare("UPDATE pendencias SET aplicador=?, responsavel_oc=?, envolvidos=?, valor=?, horario=?, status=? WHERE id=?");
                $stmt->execute([$data['aplicador'], $data['responsavel_oc'], $data['envolvidos'], $data['valor'], $data['horario'], $status, $id]);
                $_SESSION['flash_msg'] = "Registro #$id atualizado!";
            } else {
                
                $membros = array_filter(explode(',', $data['envolvidos']));
                $stmt = $pdo->prepare("INSERT INTO pendencias (aplicador, responsavel_oc, envolvidos, valor, horario, status) VALUES (?, ?, ?, ?, ?, 1)");
                foreach ($membros as $membro) {
                    $stmt->execute([$data['aplicador'], $data['responsavel_oc'], trim($membro), $data['valor'], $data['horario']]);
                }
                $_SESSION['flash_msg'] = "Pendência(s) registrada(s) com sucesso!";
            }
        }
        header("Location: /systempho/pendencia");
        exit;
}


$statsQuery = $pdo->query("SELECT status, COUNT(*) as total FROM pendencias GROUP BY status");
$counts = [0 => 0, 1 => 0, 2 => 0];
while ($row = $statsQuery->fetch())
    $counts[(int) $row['status']] = (int) $row['total'];
$twig->addGlobal('stats', ['verificado' => $counts[0], 'pendente' => $counts[1], 'pago' => $counts[2], 'total' => array_sum($counts)]);

$contQuery = $pdo->query("SELECT envolvidos, SUM(valor) as total_valor FROM pendencias WHERE status = 1 GROUP BY envolvidos ORDER BY total_valor DESC");
$twig->addGlobal('contabilizacao', $contQuery->fetchAll());


$where = " WHERE 1=1 ";
$params_query = [];
if ($filtro['status'] !== '-1') {
    $where .= " AND status = ? ";
    $params_query[] = (int) $filtro['status'];
}
if (!empty($filtro['q'])) {
    $where .= " AND envolvidos LIKE ? ";
    $params_query[] = "%{$filtro['q']}%";
}


if (!empty($filtro['meu'])) {
    $where .= " AND aplicador = ? ";
    $params_query[] = 'Arquiteto'; 
}

$stmt_total = $pdo->prepare("SELECT COUNT(*) FROM pendencias $where");
$stmt_total->execute($params_query);
$total_registros = $stmt_total->fetchColumn();
$total_paginas = ceil($total_registros / $limit) ?: 1;

$labels = ['-1' => 'Todas as Pendências', '0' => 'Verificadas', '1' => 'Pendentes', '2' => 'Pagas'];
$f_label = $labels[$filtro['status']] ?? 'Filtradas';
if (!empty($filtro['meu']))
    $f_label = "Minhas Pendências ($f_label)";

$params = [
    'link' => '/systempho/pendencia',
    'package' => 'pendencia',
    'conta' => ['username' => 'Arquiteto'],
    'filtro' => $filtro,
    'filtro_label' => $f_label,
    'page' => $action,
    'paginacao' => ['atual' => $pagina_atual, 'total' => $total_paginas, 'registros' => $total_registros]
];

if ($action === 'aplicar') {
    $view = 'aplicar.twig';
} elseif ($action === 'editar' || $action === 'ver' || $action === 'apagar') {
    $view = "$action.twig";
    $stmt = $pdo->prepare("SELECT * FROM pendencias WHERE id = ?");
    $stmt->execute([$id]);
    $params['item'] = $stmt->fetch() ?: false;
} else {
    $view = 'index.twig';
    $stmt = $pdo->prepare("SELECT * FROM pendencias $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params_query);
    $params['lista'] = $stmt->fetchAll();
}

try {
    echo $twig->render($view, $params);
} catch (Exception $e) {
    echo $e->getMessage();
}
