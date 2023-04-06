<?php

use DI\Container;
use Slim\Views\Twig;
use Slim\Factory\AppFactory;
use Slim\Views\TwigMiddleware;
use Slim\Middleware\MethodOverrideMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();
AppFactory::setContainer($container);

$container->set('db', function () {
    $db = new \PDO("sqlite:" . __DIR__ . '/../database/database.sqlite');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(\PDO::ATTR_TIMEOUT, 5000);
    $db->exec("PRAGMA journal_mode = WAL");
    return $db;
});

$app = AppFactory::create();

$app->addBodyParsingMiddleware();

$twig = Twig::create(__DIR__ . '/../twig', ['cache' => false]);

$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello world!");
    return $response;
});

$app->get('/products', function (Request $request, Response $response, $args) {

    $db = $this->get('db');
    $sth = $db->prepare("SELECT * FROM products");
    $sth->execute();
    $products = $sth->fetchAll(\PDO::FETCH_OBJ);

    $view = Twig::fromRequest($request);
    return $view->render($response, 'products.html', [
        'products' => $products
    ]);
});

$app->post('/add-cart', function (Request $request, Response $response, $args) {
    $parsedBody = $request->getParsedBody();

    if (isset($parsedBody['id']) == false) {
        return $response->withStatus(400);
    }
    $rawProducts = json_decode($_COOKIE['cart'] ?? '', true);
    
    if( $rawProducts[$parsedBody['id']]) {
        $rawProducts[$parsedBody['id']] = $rawProducts[$parsedBody['id']] + 1;
    } else {
        $rawProducts[$parsedBody['id']] = 1;
    }

    setcookie("cart", json_encode($rawProducts), time() + 3600, "/");

    return $response->withHeader('Location', '/products')->withStatus(302);
});

$app->post('/remove-cart', function (Request $request, Response $response, $args) {
    $parsedBody = $request->getParsedBody();
    if (isset($parsedBody['id']) == false) {
        return $response->withStatus(400);
    }
    $rawProducts = json_decode($_COOKIE['cart'] ?? '', true);
    $prod_id = $parsedBody['id'];
    unset($rawProducts[$prod_id]);
    setcookie("cart", json_encode($rawProducts), time() + 3600, "/");

    return $response->withHeader('Location', '/cart')->withStatus(302);
});

$app->get('/cart', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    if (isset($_COOKIE["cart"])) {
        $rawProducts = json_decode($_COOKIE['cart'] ?? '', true);
        if (empty($rawProducts)) {
            echo '<style>a {font-size: 50px;}</style><h1>Корзина пуста!</h1><a href="/products"><<<Вернуться</a>';
            return $response->withStatus(400);
        }
    }
    else {
        $response->getBody()->write("Корзина пуста");
        return $response->withStatus(400);
    }
    
    $products = array();
    foreach ($rawProducts as $prod_id => $quantity) {
        $smth = $db->prepare("SELECT * FROM products WHERE id=:product_id");
        $smth->execute(array(':product_id' => $prod_id));
        $product = $smth->fetch(PDO::FETCH_ASSOC);
        $product['quantity'] = $quantity;
        $products[] = $product;
    }
    $view = Twig::fromRequest($request);
    return $view->render($response, 'cart.html', [
        'products' => $products
    ]);
});

$methodOverrideMiddleware = new MethodOverrideMiddleware();
$app->add($methodOverrideMiddleware);

$app->add(TwigMiddleware::create($app, $twig));
$app->run();
