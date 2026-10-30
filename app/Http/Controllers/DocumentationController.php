<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DocumentationController extends Controller
{
    /**
     * Daftar panduan deployment yang dapat dipratinjau.
     *
     * Panduan dipisah per sistem operasi agar instruksi Windows dan Linux tidak
     * tercampur; halaman ringkasan tetap tersedia sebagai penentu jalur.
     *
     * @var array<string, array{file: string, title: string, subtitle: string}>
     */
    private const DEPLOYMENT_GUIDES = [
        'overview' => [
            'file' => 'PANDUAN_DEPLOYMENT.md',
            'title' => 'Panduan Deployment',
            'subtitle' => 'Ringkasan pemilihan jalur instalasi Windows dan Linux',
        ],
        'windows' => [
            'file' => 'docs/PANDUAN_DEPLOYMENT_WINDOWS.md',
            'title' => 'Deployment Windows',
            'subtitle' => 'Bundle siap pakai, wizard, layanan Windows, dan connector C#',
        ],
        'linux' => [
            'file' => 'docs/PANDUAN_DEPLOYMENT_LINUX.md',
            'title' => 'Deployment Linux',
            'subtitle' => 'Server headless/CLI: Nginx, PHP-FPM, systemd, dan connector Python',
        ],
    ];

    /**
     * Tampilkan halaman panduan penggunaan aplikasi (menu: Dokumentasi).
     */
    public function index(): View
    {
        return view('documentation.index');
    }

    /**
     * Tampilkan ringkasan pemilihan jalur deployment.
     */
    public function deployment(): View
    {
        return $this->renderDeploymentGuide('overview');
    }

    /**
     * Tampilkan panduan deployment Windows.
     */
    public function deploymentWindows(): View
    {
        return $this->renderDeploymentGuide('windows');
    }

    /**
     * Tampilkan panduan deployment Linux (headless/CLI).
     */
    public function deploymentLinux(): View
    {
        return $this->renderDeploymentGuide('linux');
    }

    /**
     * Render berkas Markdown panduan deployment menjadi halaman HTML.
     */
    private function renderDeploymentGuide(string $platform): View
    {
        $guide = self::DEPLOYMENT_GUIDES[$platform];
        $path = base_path($guide['file']);

        $guides = collect(self::DEPLOYMENT_GUIDES)
            ->map(fn (array $item, string $key): array => [
                'key' => $key,
                'title' => $item['title'],
                'route' => match ($key) {
                    'windows' => route('documentation.deployment.windows'),
                    'linux' => route('documentation.deployment.linux'),
                    default => route('documentation.deployment'),
                },
            ])
            ->values()
            ->all();

        if (! File::isFile($path)) {
            return view('documentation.deployment', [
                'html' => null,
                'updatedAt' => null,
                'activePlatform' => $platform,
                'guideTitle' => $guide['title'],
                'guideSubtitle' => $guide['subtitle'],
                'fileName' => $guide['file'],
                'guides' => $guides,
            ]);
        }

        $modifiedAt = File::lastModified($path);

        $html = Str::markdown(File::get($path), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = preg_replace('/<h1>.*?<\/h1>/s', '', $html, 1);

        $html = preg_replace('/<table>/', '<div class="doc-table-wrap"><table>', $html);
        $html = preg_replace('/<\/table>/', '</table></div>', $html);

        $html = $this->rewriteGuideLinks($html);

        return view('documentation.deployment', [
            'html' => $html,
            'updatedAt' => $modifiedAt
                ? Carbon::createFromTimestamp($modifiedAt)->translatedFormat('d F Y, H:i')
                : null,
            'activePlatform' => $platform,
            'guideTitle' => $guide['title'],
            'guideSubtitle' => $guide['subtitle'],
            'fileName' => $guide['file'],
            'guides' => $guides,
        ]);
    }

    /**
     * Arahkan tautan antar-berkas panduan ke route aplikasi, bukan ke berkas mentah.
     */
    private function rewriteGuideLinks(string $html): string
    {
        $map = [
            'docs/PANDUAN_DEPLOYMENT_WINDOWS.md' => route('documentation.deployment.windows'),
            'PANDUAN_DEPLOYMENT_WINDOWS.md' => route('documentation.deployment.windows'),
            'docs/PANDUAN_DEPLOYMENT_LINUX.md' => route('documentation.deployment.linux'),
            'PANDUAN_DEPLOYMENT_LINUX.md' => route('documentation.deployment.linux'),
            'PANDUAN_DEPLOYMENT.md' => route('documentation.deployment'),
        ];

        foreach ($map as $file => $url) {
            $html = str_replace('href="'.$file.'"', 'href="'.$url.'"', $html);
        }

        return $html;
    }
}
