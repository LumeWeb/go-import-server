<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\TwigMiddleware;
use Symfony\Component\Yaml\Yaml;
use Slim\Views\Twig;

// Load Composer's autoloader
require __DIR__ . '/../vendor/autoload.php';

// Create a new Slim app
$app = AppFactory::create();

// Load configuration from YAML file
$config = Yaml::parseFile(dirname(__DIR__) . '/config/config.yaml');

// Create Twig
$twig = Twig::create(dirname(__DIR__) . '/templates', ['cache' => false]);

// Add Twig-View middleware
$app->add(TwigMiddleware::create($app, $twig));

if (!isset($config['import_paths'])) {
	$config['import_paths'] = [];
}

// Import paths, repository roots, and VCS
$importPaths = $config['import_paths'];

// Define a route for all requests
$app->any('/{path:.*}', function (Request $request, Response $response, array $args) use ($importPaths) {
	$host = $request->getUri()->getHost();
	$path = $args['path'];

	// Check if the request is for the root of a subdomain
	if (empty($path)) {
		$response->getBody()->write('404 Not Found');
		return $response->withStatus(404);
	}

	foreach ($importPaths as $pathConfig) {
		$importPath = $pathConfig['path'];
		$repoPath = $pathConfig['repo_path'];
		$vcs = $pathConfig['vcs'] ?? 'git';
		$branch = $pathConfig['branch'];

		// Wildcard handling
		$wildcard = (substr($importPath, -2) === '/*' && substr($repoPath, -2) === '/*');
		$pathParts = explode('/',  $path);
		if ($wildcard) {
			$importPath = rtrim($importPath, '/*');
			$repoPath = rtrim($repoPath, '/*');
			$hostParts = explode('.', $host);
			$wildcardPart = array_shift($hostParts);

			while ($wildcardPart !== $importPath && !empty($hostParts)) {
				$wildcardPart .= '.' . array_shift($hostParts);
			}

			if ($wildcardPart !== $importPath) {
				continue; // Skip to the next import path if the host does not match
			}

			$importRoot = $importPath;
			$vcsRoot = $repoPath;

			if (!empty($pathParts)) {
				$importRoot .= '/' . $pathParts[0];
				$vcsRoot .= '/' . $pathParts[0];
			}

		} else {
			$importRoot = $importPath;
			$vcsRoot = $repoPath;
		}

		// Check if the host matches the import path
		if ($host === $importPath || $wildcard) {
			// Check if the remaining path starts with a valid Go package segment
			$validPackagePath = true;

			if (!empty($pathParts)) {
				$firstSegment = array_shift($pathParts);
				if (preg_match('/^[a-zA-Z0-9_]+$/', $firstSegment) === false) {
					$validPackagePath = false;
					break;
				}
			}

			if ($validPackagePath) {
				$data = [
					'ImportRoot' => rtrim( $importRoot, '/' ),
					'VCS'        => $vcs,
					'VCSRoot'    => rtrim( $vcsRoot, '/' ),
					"Branch" => $branch
				];
				return Twig::fromRequest( $request )->render( $response, 'redirect.html.twig', $data );
			}
		}
	}
});

$app->run();
