<?php

class OllamaTranslator {
    private $ollamaUrl;
    private $model;
    private $maxRetries;
    
    public function __construct($ollamaUrl = 'http://192.168.1.7', $model = 'qwen2.5-coder:14b', $maxRetries = 3) {
        $this->ollamaUrl = $ollamaUrl;
        $this->model = $model;
        $this->maxRetries = $maxRetries;
    }
    
    public function translate($text) {
        $prompt = "请将以下英文翻译成中文，只返回翻译结果，不要其他内容：" . $text;
        
        $data = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false
        ];
        
        // 重试机制
        for ($retry = 0; $retry < $this->maxRetries; $retry++) {
            try {
                if ($retry > 0) {
                    echo "重试第 {$retry} 次... ";
                    sleep(2); // 重试前等待2秒
                }
                
                if (function_exists('curl_init')) {
                    return $this->translateWithCurl($data);
                } else {
                    return $this->translateWithFileGetContents($data);
                }
            } catch (Exception $e) {
                if ($retry === $this->maxRetries - 1) {
                    throw $e; // 最后一次重试失败则抛出异常
                }
                echo "请求失败，准备重试... ";
            }
        }
    }
    
    private function translateWithCurl($data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->ollamaUrl . '/api/generate');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 增加超时时间到60秒
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("CURL错误: {$error}");
        }
        
        if ($httpCode !== 200 || !$response) {
            throw new Exception("Ollama API 请求失败: HTTP {$httpCode}");
        }
        
        return $this->parseResponse($response);
    }
    
    private function translateWithFileGetContents($data) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($data),
                'timeout' => 60 // 增加超时时间到60秒
            ]
        ]);
        
        $response = file_get_contents($this->ollamaUrl . '/api/generate', false, $context);
        
        if ($response === false) {
            throw new Exception("Ollama API 请求失败");
        }
        
        return $this->parseResponse($response);
    }
    
    private function parseResponse($response) {
        $result = json_decode($response, true);
        if (!isset($result['response'])) {
            throw new Exception("Ollama API 返回格式错误: " . substr($response, 0, 200));
        }
        
        $translatedText = trim($result['response']);
        if (empty($translatedText)) {
            throw new Exception("翻译结果为空");
        }
        
        return $translatedText;
    }
    
    public function translateFile($inputFile, $outputFile) {
        if (!file_exists($inputFile)) {
            throw new Exception("输入文件不存在: {$inputFile}");
        }
        
        $content = file_get_contents($inputFile);
        $lines = explode("\n", $content);
        $translatedLines = [];
        // 修改正则表达式以支持多层数组键，如 ['wizard']['settingsCountryDescription']
        $pattern = '/\$_ADMINLANG\[\'([^\']+)\'\](?:\[\'([^\']+)\'\])*\s*=\s*"([^"]+)";/';
        $totalTranslated = 0;
        $totalLines = count($lines);
        
        foreach ($lines as $lineNumber => $line) {
            echo "处理第 " . ($lineNumber + 1) . "/{$totalLines} 行...\n";
            
            if (preg_match($pattern, $line, $matches)) {
                // 提取完整的键路径和英文文本
                $fullKey = $this->extractFullKey($line);
                $englishText = end($matches); // 最后一个匹配项是英文文本
                
                try {
                    echo "翻译: {$englishText} -> ";
                    flush(); // 立即输出缓冲区内容
                    
                    $chineseText = $this->translate($englishText);
                    echo "{$chineseText}\n";
                    
                    // 重新构建完整的行，保持原有的键结构
                    $translatedLine = str_replace('"' . $englishText . '"', '"' . $chineseText . '"', $line);
                    $translatedLines[] = $translatedLine;
                    $totalTranslated++;
                    
                    echo "已翻译: {$totalTranslated} 条\n";
                    
                    // 每次翻译后等待1秒，确保API处理完成
                    sleep(1);
                    
                } catch (Exception $e) {
                    echo "翻译失败: " . $e->getMessage() . "，保持原文\n";
                    $translatedLines[] = $line;
                }
            } else {
                $translatedLines[] = $line;
            }
            
            // 每100行保存一次进度
            if (($lineNumber + 1) % 100 === 0) {
                echo "进度保存中...\n";
                $tempContent = implode("\n", $translatedLines);
                file_put_contents($outputFile . '.temp', $tempContent);
            }
        }
        
        $translatedContent = implode("\n", $translatedLines);
        file_put_contents($outputFile, $translatedContent);
        
        // 删除临时文件
        if (file_exists($outputFile . '.temp')) {
            unlink($outputFile . '.temp');
        }
        
        echo "翻译完成！总共翻译了 {$totalTranslated} 条，结果已保存到: {$outputFile}\n";
    }
    
    /**
     * 提取完整的数组键路径
     */
    private function extractFullKey($line) {
        // 匹配所有的数组键
        if (preg_match('/\$_ADMINLANG(\[\'[^\']+\'\]+)/', $line, $matches)) {
            return $matches[1];
        }
        return '';
    }
}

// 命令行参数处理
if ($argc < 3) {
    echo "用法: php translate.php <输入文件> <输出文件> [模型名称]\n";
    echo "示例: php translate.php input.php output.php qwen2.5-coder:14b\n";
    exit(1);
}

$inputFile = $argv[1];
$outputFile = $argv[2];
$model = isset($argv[3]) ? $argv[3] : 'qwen2.5-coder:14b';

try {
    $translator = new OllamaTranslator('http://192.168.1.7', $model);
    $translator->translateFile($inputFile, $outputFile);
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
?>
