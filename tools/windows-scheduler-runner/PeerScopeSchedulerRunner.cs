using System;
using System.Diagnostics;
using System.IO;

internal static class PeerScopeSchedulerRunner
{
    private const string DefaultPhpPath = @"C:\xampp\php\php.exe";
    private const string DefaultProjectPath = @"C:\xampp\htdocs\PeerScope";
    private const string DefaultLogPath = @"C:\xampp\htdocs\PeerScope\storage\logs\scheduler-runner.log";

    private static int Main()
    {
        // 既定はXAMPP標準配置にし、別環境ではタスク側の環境変数で差し替える。
        var phpPath = Environment.GetEnvironmentVariable("PEERSCOPE_PHP_PATH") ?? DefaultPhpPath;
        var projectPath = Environment.GetEnvironmentVariable("PEERSCOPE_PROJECT_PATH") ?? DefaultProjectPath;
        var logPath = Environment.GetEnvironmentVariable("PEERSCOPE_SCHEDULER_LOG_PATH") ?? DefaultLogPath;

        try
        {
            // winexeとしてビルドし、さらにCreateNoWindowを指定してコマンド画面を出さずに実行する。
            var startInfo = new ProcessStartInfo
            {
                FileName = phpPath,
                Arguments = "artisan schedule:run",
                WorkingDirectory = projectPath,
                CreateNoWindow = true,
                UseShellExecute = false,
                WindowStyle = ProcessWindowStyle.Hidden,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            };

            using (var process = Process.Start(startInfo))
            {
                if (process == null)
                {
                    WriteLog(logPath, "PHP process did not start.");

                    return 1;
                }

                var output = process.StandardOutput.ReadToEnd();
                var error = process.StandardError.ReadToEnd();
                process.WaitForExit();

                if (process.ExitCode != 0)
                {
                    WriteLog(logPath, "ExitCode=" + process.ExitCode + Environment.NewLine + output + Environment.NewLine + error);
                }

                return process.ExitCode;
            }
        }
        catch (Exception exception)
        {
            WriteLog(logPath, exception.ToString());

            return 1;
        }
    }

    private static void WriteLog(string logPath, string message)
    {
        // Laravelの通常ログとは分け、Runner自体の起動失敗だけを記録する。
        var directory = Path.GetDirectoryName(logPath);

        if (! string.IsNullOrEmpty(directory))
        {
            Directory.CreateDirectory(directory);
        }

        File.AppendAllText(logPath, DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + Environment.NewLine + message + Environment.NewLine);
    }
}
