<?php
namespace Md2Epub;

use \Rain\Tpl;

/**
 * eBook manager class
 *
 * @author Vito Tardia <http://vtardia.com>
 */
class EBook
{
    protected $defaults = array(
        'content_dir' => 'OEBPS',
        'book_info'   => 'book.json'
    );
    protected $optParams = array(
        'authors',
        'date',
        'description',
        'publisher',
        'relation',
        'rights',
        'subject'
    );
    protected $params = array();

    protected $id       = '';
    protected $title    = '';
    protected $language = '';
    protected $files    = array();
    protected $spine    = array();

    /**
     * Initialize a new ebook
     *
     * @param  string  $srcDir  The directory that contains the source files
     * @param  array   $params  Additional settings
     */
    public function __construct($srcDir, $params = array())
    {
        $this->params = array_merge($this->defaults, $params);

        $srcDir = realpath($srcDir);
        if (!is_dir($srcDir)) {
            throw new \Exception("'$srcDir' is not a directory!");
        }

        $infoFile = "$srcDir/{$this->params['book_info']}";
        if (!file_exists($infoFile)) {
            throw new \Exception("JSON Book file '{$this->params['book_info']}' doesn't exist in '$srcDir'!");
        }

        $bookInfo = json_decode(file_get_contents($infoFile), true);
        if (json_last_error() != JSON_ERROR_NONE) {
            throw new \Exception("Error parsing JSON book file");
        }

        $this->home = $srcDir;

        $this->id = $bookInfo['id'];
        $this->title = $bookInfo['title'];
        $this->language = $bookInfo['language'];

        foreach ($this->optParams as $p) {
            if (isset($bookInfo[$p])) {
                $this->$p = $bookInfo[$p];
            }
        }

        $this->files = $this->parseFiles($bookInfo['files']);
        $this->spine = $this->parseSpine($bookInfo['spine']);
    }

    protected function parseFiles($files)
    {
        $bookFiles = array();

        // extract includes and exclude files
        $includes = $files['include'];
        unset($files['include']);

        // these are ignored for the moment
        $excludes = $files['exclude'];
        unset($files['exclude']);

        // process "declared" files
        foreach ($files as $id => $path) {
            $bookFiles[$id] = array(
                'path' => $path,
                'type' => $this->mime("{$this->home}/$path")
            );
            $excludes[] = $path;
        }

        // process include files
        foreach ($includes as $item) {
            // an array file must have an ID and a string PATH
            if (is_array($item) && array_key_exists('id', $item) && array_key_exists('path', $item)) {
                $id = $item['id'];
                $path = $item['path'];
                $type = $this->mime("{$this->home}/$path");

                // the NCX file is included if present and generated automatically later
                if (pathinfo($path, PATHINFO_EXTENSION) == 'ncx' ||
                    (!in_array($path, $excludes) && is_file("{$this->home}/$path") &&
                        is_readable("{$this->home}/$path"))) {

                    $bookFiles[$id] = array(
                        'path' => $path,
                        'type' => $type
                    );
                }
                continue;
            }

            // a string file can be a single file or a glob() expression
            $paths = glob("{$this->home}/$item");
            foreach ($paths as $p) {
                if (is_file($p) && is_readable($p)) {
                    $id = $this->generateFileId(pathinfo($p, PATHINFO_FILENAME));
                    $path = str_replace("{$this->home}/", '', $p);
                    $type = $this->mime("{$this->home}/$path");

                    if (!in_array($path, $excludes)) {
                        $bookFiles[$id] = array(
                            'path' => $path,
                            'type' => $type
                        );
                    }
                }
            }
        }

        return $bookFiles;
    }

    protected function parseSpine($spine)
    {
        $bookSpine = array('items' => array());

        if (isset($this->files[$spine['toc']])) {
            $bookSpine['toc'] = $spine['toc'];
        }

        foreach ($spine['items'] as $item) {
            $wildcards = array();
            if (preg_match('/^\|.*\|$/', $item, $wildcards)) {
                if (!empty($wildcards[0])) {
                    $pattern = str_replace('|', '/', $wildcards[0]);
                    foreach (array_keys($this->files) as $key) {
                        if (preg_match($pattern, $key)) {
                            $bookSpine['items'][] = $key;
                        }
                    }
                }
            } elseif (isset($this->files[$item])) {
                $bookSpine['items'][] = $item;
            }
        }

        return $bookSpine;
    }

    public function makeEpub($params = array())
    {
        // check and init working directory
        if (!empty($params['working_dir'])) {
            $workDir = $params['working_dir'];
        } else {
            throw new \Exception("Please, provide a working directory path");
        }

        // check and init target file
        if (!empty($params['out_file'])) {
            $epubFile = $params['out_file'];
        } else {
            throw new \Exception("Please, provide path for the destination ePub file");
        }

        // check and init working directory
        if (!empty($params['templates_dir'])) {
            $this->params['templates_dir'] = $params['templates_dir'];
        } else {
            throw new \Exception("Please, provide a template directory path");
        }

        if (!is_dir($workDir)) {
            throw new \Exception("'{$workDir}' is not a directory!");
        }

        if (!is_writable($workDir)) {
            throw new \Exception("'{$workDir}' is not writeable!");
        }

        // create cotent directory (OEBPS is default)
        if (!mkdir("$workDir/{$this->params['content_dir']}")) {
            throw new \Exception("Unable to create content directory '{$this->params['content_dir']}'");
        }

        // create the META-INF directory and container.xml file
        $this->createMetaInf($workDir);

        // export files
        $filters = (!empty($params['filters'])) ? $params['filters'] : array();
        $this->exportBookFiles($workDir, $filters);

        // create the content.opf descriptor file
        $this->createOpf($workDir);

        // create the NXC toc file, if needed
        $this->createNcx($workDir);

        $this->createArchive($workDir, $epubFile);
    }

    protected function initTemplateEngine($params)
    {
        $defaults = array(
            'tpl_dir'   => "{$this->params['templates_dir']}/",
            'cache_dir' => sys_get_temp_dir() . '/'
        );
        $params = array_merge($defaults, $params);

        // init template engine
        $tpl = new Tpl();
        $tpl->configure($params);
        return $tpl;
    }

    protected function createMetaInf($workDir)
    {
        // create destination directory
        if (!mkdir("$workDir/META-INF")) {
            throw new \Exception("Unable to create content META-INF directory");
        }

        // compile file
        $tpl = $this->initTemplateEngine(array('tpl_ext' => 'xml'));
        $tpl->assign('ContentDirectory', $this->params['content_dir']);
        $container = $tpl->draw('book/META-INF/container', true);

        // write compiled file to destination
        if (file_put_contents($workDir . '/META-INF/container.xml', $container) === false) {
            throw new \Exception("Unable to create content META-INF/container.xml");
        }
    }

    protected function createOpf($workDir)
    {
        // compile file
        $tpl = $this->initTemplateEngine(array('tpl_ext' => 'opf'));
        $tpl->assign('BookID', $this->id);
        $tpl->assign('BookTitle', $this->title);
        $tpl->assign('BookLanguage', $this->language);

        foreach ($this->optParams as $p) {
            if (isset($this->$p)) {
                $tpl->assign('Book' . ucfirst($p), $this->$p);
            }
        }

        $tpl->assign('BookFiles', $this->files);
        $tpl->assign('BookSpine', $this->spine);

        $content = $tpl->draw('book/OEBPS/content', true);

        // write compiled file to destination
        if (file_put_contents("$workDir/{$this->params['content_dir']}/content.opf", $content) === false) {
            throw new \Exception("Unable to create content.opf");
        }
    }

    protected function createNcx($workDir)
    {
        if (isset($this->files['ncx'])) {

            $tpl = $this->initTemplateEngine(array('tpl_ext' => 'ncx'));
            $tpl->assign('BookID', $this->id);
            $tpl->assign('BookTitle', $this->title);

            // Obtaining book chapters from the spine parameter
            // Each XML-compatible file is loaded and parsed for H1 (chapters) and H2 (subchapters) tags
            $chapters = array();
            libxml_use_internal_errors(true);
            foreach ($this->spine['items'] as $item) {
                $doc = simplexml_load_file("$workDir/{$this->params['content_dir']}/{$this->files[$item]['path']}");
                if ($doc && isset($doc->body->h1)) {
                    // chapter title
                    $chapters[$item] = array(
                        'title' => $doc->body->h1,
                        'path'  => $this->files[$item]['path']
                    );

                    // subchapter title
                    foreach ($doc->body->h2 as $section) {
                        if (!empty($section['id'])) {
                            $section_id = (string) $section['id'];
                            $chapters[$item]['sections'][$section_id] = array(
                                'title' => $section,
                                'path' => $this->files[$item]['path'] . '#' . $section['id']
                            );
                        }
                    }
                }
            }

            if (!empty($chapters)) {
                $tpl->assign('BookChapters', $chapters);
            }

            $toc = $tpl->draw('book/OEBPS/toc', true);

            // write compiled file to destination
            if (file_put_contents("$workDir/{$this->params['content_dir']}/toc.ncx", $toc) === false) {
                throw new \Exception("Unable to create toc.ncx");
            }
        }
    }


    protected function exportBookFiles($workDir, $filters = array())
    {
        foreach ($this->files as $id => $file) {
            $src  = "{$this->home}/{$file['path']}";
            $dest = "$workDir/{$this->params['content_dir']}/{$file['path']}";
            $info = pathinfo($file['path']);
            $ext = $info['extension'];

            // if the file has a filter process then copy to destination directory, else copy only
            if (!empty($filters[$ext]) && is_callable($filters[$ext])) {
                // load file content and process it using the filter function
                $content = file_get_contents($src);
                if ($content === false) {
                    throw new \Exception("Unable to load file '$src'");
                }
                $content = call_user_func($filters[$ext], $content);

                // you can use a custom template named after the file ID or the default page.xhtml template
                $template = 'page';
                if (file_exists("{$this->params['templates_dir']}/book/OEBPS/$id.xhtml")) {
                    $template = $id;
                }

                // compile template
                $tpl = $this->initTemplateEngine(
                    array(
                        'tpl_ext' => 'xhtml',
                        'path_replace' => false,
                        'auto_escape' => false,
                    )
                );

                $tpl->assign('BookTitle', $this->title);
                $tpl->assign('BookContent', $content);
                if (file_exists("{$this->home}/style.css")) {
                    $tpl->assign('BookStyle', 'style.css');
                }
                if (isset($this->description)) {
                    $tpl->assign('BookDescription', $this->description);
                }

                $content = $tpl->draw("book/OEBPS/$template", true);

                // save compiled file to the new destination
                $dest = "$workDir/{$this->params['content_dir']}/{$info['dirname']}/{$info['filename']}.xhtml";
                if (!is_dir(dirname($dest))) {
                    if (!mkdir(dirname($dest), 0777, true)) {
                        throw new \Exception("Unable to create path '" . dirname($dest) . "'");
                    }
                }

                if (file_put_contents($dest, $content) === false) {
                    throw new \Exception("Unable to create file '$dest'");
                }

                // update the files variable to reflect the change
                $this->files[$id] = array(
                    'type' => $this->mime($dest),
                );
                $this->files[$id]['path'] = ('.' != $info['dirname']) ? "{$info['dirname']}/" : '';
                $this->files[$id]['path'] .= "{$info['filename']}.xhtml";

                continue;
            }

            // Default behavior: copy the original file
            // Must check that the destination path exists
            if (!is_dir(dirname($dest))) {
                if (!mkdir(dirname($dest), 0777, true)) {
                    throw new \Exception("Unable to create path '" . dirname($dest) . "'");
                }
            }
            if (file_exists($src)) {
                copy($src, $dest);
            }
        }
    }

    protected function createArchive($workDir, $epubFile)
    {
        $excludes = array('.DS_Store', 'mimetype');

        $mimeZip = "{$this->params['templates_dir']}/mimetype.zip";
        $zipFile = sys_get_temp_dir() . '/book.zip';

        if (!copy($mimeZip, $zipFile)) {
            throw new \Exception("Unable to copy temporary archive file");
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE) != true) {
            throw new \Exception("Unable open archive '$zipFile'");
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workDir), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($files as $file) {
            if (in_array(basename($file), $excludes)) {
                continue;
            }
            if (is_dir($file)) {
                $zip->addEmptyDir(str_replace("$workDir/", '', "$file/"));
            } elseif (is_file($file)) {
                $zip->addFromString(
                    str_replace("$workDir/", '', $file),
                    file_get_contents($file)
                );
            }
        }
        $zip->close();

        rename($zipFile, $epubFile);
    }

    public function getParam($name)
    {
        if (isset($this->params[$name])) {
            return $this->params[$name];
        }
        return null;
    }

    public function id()
    {
        return $this->id;
    }

    public function title()
    {
        return $this->title;
    }

    public function language()
    {
        return $this->language;
    }

    public function files()
    {
        return $this->files;
    }

    protected function generateFileId($filename)
    {
        $filename = strtolower($filename);
        $filename = str_replace(array(' ', '.'), '-', $filename);
        $filename = preg_replace('/^(\d+)(.*)/', 'c$1$2', $filename);

        return $filename;
    }

    protected function mime($file)
    {
        $mime = '';
        if (file_exists($file)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file);
            finfo_close($finfo);
        }

        // fix mime types
        $fileinfo = pathinfo($file);
        switch ($fileinfo['extension']) {
            case 'xhtml':
                $mime = 'application/xhtml+xml';
                break;
            case 'css':
                $mime = 'text/css';
                break;
            case 'ncx':
                $mime = 'application/x-dtbncx+xml';
                break;
        }

        return $mime;
    }
}
