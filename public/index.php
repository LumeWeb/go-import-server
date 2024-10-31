<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\TwigMiddleware;
use Symfony\Component\Yaml\Yaml;
use Slim\Views\Twig;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

// Load configuration from YAML file
$config = Yaml::parseFile(dirname(__DIR__) . '/config/config.yaml');

// Create Twig
$twig = Twig::create(dirname(__DIR__) . '/templates', ['cache' => false]);
$app->add(TwigMiddleware::create($app, $twig));

// Ensure import_paths exists
$importPaths = $config['import_paths'] ?? [];

// Validate if a path follows Go package naming rules
function isValidGoPackagePath(string $path): bool {
	if (empty($path)) {
		return false;
	}

	$segments = explode('/', trim($path, '/'));
	foreach ($segments as $segment) {
		if (!preg_match('/^[a-zA-Z0-9_\-.]+$/', $segment)) {
			return false;
		}
	}
	return true;
}

function isExactMatch(string $requestPath, array $pathConfig): bool {
	if (substr($pathConfig['path'], -2) === '/*') {
		return false;
	}
	return $requestPath === $pathConfig['path'];
}

function findMostSpecificPath(string $host, string $path, array $importPaths): ?array {
	$requestPath = rtrim($host . '/' . $path, '/');

	// First try exact matches
	foreach ($importPaths as $pathConfig) {
		if (isExactMatch($requestPath, $pathConfig)) {
			return $pathConfig;
		}
	}

	// Skip intermediate paths
	$pathParts = explode('/', $path);
	if (count($pathParts) > 1 && count($pathParts) < 3) {
		return null;
	}

	// Then try wildcard matches for full paths only
	foreach ($importPaths as $pathConfig) {
		if (substr($pathConfig['path'], -2) === '/*') {
			$basePath = rtrim($pathConfig['path'], '/*');
			if (strpos($requestPath, $basePath) === 0) {
				return $pathConfig;
			}
		}
	}

	return null;
}



$app->any('/{path:.*}', function (Request $request, Response $response, array $args) use ($importPaths) {
	$host = $request->getUri()->getHost();
	$path = trim($args['path'] ?? '', '/');

	$matchedConfig = findMostSpecificPath($host, $path, $importPaths);

	if (!$matchedConfig || !isValidGoPackagePath($path)) {
		$response->getBody()->write('404 Not Found');
		return $response->withStatus(404);
	}

	$requestPath = $host;
	if (!empty($path)) {
		$requestPath .= '/' . $path;
	}

	$data = [
		'ImportRoot' => $requestPath,
		'VCS' => $matchedConfig['vcs'] ?? 'git',
		'VCSRoot' => rtrim($matchedConfig['repo_path'], '/'),
		'Branch' => $matchedConfig['branch'] ?? 'main'
	];

	return Twig::fromRequest($request)->render($response, 'redirect.html.twig', $data);
});

$app->run();
