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

// Helper function to parse domain parts
function parseHostParts(string $host, string $importPath): ?array {
	$hostParts = explode('.', $host);
	$importParts = explode('.', rtrim($importPath, '/*'));

	// Handle exact matching first
	if ($importPath === $host) {
		return [
			'isWildcard' => false,
			'matched' => $hostParts,
			'wildcard' => []
		];
	}

	// Handle wildcard matching
	if (substr($importPath, -2) === '/*') {
		// Check if the base domain matches
		$baseDomain = implode('.', $importParts);
		if ($host === $baseDomain) {
			return [
				'isWildcard' => true,
				'matched' => $hostParts,
				'wildcard' => []
			];
		}
	}

	return null;
}

// Helper function to validate Go package path
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

$app->any('/{path:.*}', function (Request $request, Response $response, array $args) use ($importPaths) {
	$host = $request->getUri()->getHost();
	$path = trim($args['path'] ?? '', '/');

	foreach ($importPaths as $pathConfig) {
		$importPath = $pathConfig['path'];
		$repoPath = $pathConfig['repo_path'];
		$vcs = $pathConfig['vcs'] ?? 'git';
		$branch = $pathConfig['branch'] ?? 'main';

		$hostInfo = parseHostParts($host, $importPath);
		if ($hostInfo === null) {
			continue;
		}

		// For wildcard configs, construct the proper import path
		$importRoot = $host;
		if (!empty($path)) {
			$importRoot .= '/' . $path;
		}

		// Construct VCS root
		if (substr($importPath, -2) === '/*') {
			// Extract the project name from the path
			$pathParts = explode('/', $path);
			$projectName = $pathParts[0] ?? '';

			if (empty($projectName)) {
				continue;
			}

			// Replace wildcard in repo path with actual project name
			$vcsRoot = str_replace('*', $projectName, $repoPath);
		} else {
			$vcsRoot = $repoPath;
		}

		// Validate the package path
		if (!isValidGoPackagePath($path)) {
			continue;
		}

		// Prepare template data
		$data = [
			'ImportRoot' => $importRoot,
			'VCS' => $vcs,
			'VCSRoot' => $vcsRoot,
			'Branch' => $branch
		];

		// Return the rendered template
		return Twig::fromRequest($request)->render($response, 'redirect.html.twig', $data);
	}

	// If no match is found, return 404 response
	$response->getBody()->write('404 Not Found');
	return $response->withStatus(404);
});

$app->run();
