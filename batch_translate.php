<?php

require_once 'translate.php';

class BatchTranslator {
    private $translator;
    
    public function __construct($model = 'qwen2.5-coder:14b') {
        $this->translator = new OllamaTranslator('http://192.168.1.7', $model);
    }
    
    public function translateDirectory($inputDir, $outputDir, $pattern = '*.php') {
        if (!is_dir($inputDir)) {
            throw new Exception("输入目录不存在: {$inputDir}");
        }
        
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        $files = glob($inputDir . '/' . $pattern);
        
        foreach ($files as $file) {
            $filename = basename($file);
            $outputFile = $outputDir . '/' . $filename;
            
            echo "正在处理文件: {$filename}\n";
            echo str_repeat('-', 50) . "\n";
            
            try {
                $this->translator->translateFile($file, $outputFile);
                echo "文件 {$filename} 处理完成\n\n";
            } catch (Exception $e) {
                echo "处理文件 {$filename} 时出错: " . $e->getMessage() . "\n\n";
            }
        }
    }
}

// 命令行参数处理
if ($argc < 3) {
    echo "用法: php batch_translate.php <输入目录> <输出目录> [模型名称]\n";
    echo "示例: php batch_translate.php ./lang ./lang_cn qwen2.5-coder:14b\n";
    exit(1);
}

$inputDir = $argv[1];
$outputDir = $argv[2];
$model = isset($argv[3]) ? $argv[3] : 'qwen2.5-coder:14b';

try {
    $batchTranslator = new BatchTranslator($model);
    $batchTranslator->translateDirectory($inputDir, $outputDir);
    echo "批量翻译完成！\n";
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
?>
