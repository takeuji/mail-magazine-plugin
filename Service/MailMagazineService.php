<?php
/*
* This file is part of EC-CUBE
*
* Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
* http://www.lockon.co.jp/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\MailMagazine\Service;

use Eccube\Application;
use Eccube\Common\Constant;
use Plugin\MailMagazine\Entity\MailMagazineSendHistory;
use Plugin\MailMagazine\Repository\MailMagazineCustomerRepository;

/**
 * メルマガ配信処理のサービスクラス。
 *
 * メルマガ配信処理を行います。
 * 配信履歴はエンティティ`MailMagazineSendHistory`として永続化されますが、確実に大量のメールを配信するために配信ファイルと結果ファイルも使用します。
 * 配信ファイルと結果ファイルは同じフォーマットで、以下のようなCSV形式になります。
 * <ステータス>,<メールアドレス>,<会員名>
 *
 * ・配信ファイルについて
 * メルマガ配信前に作成され、すべてのメールを送信完了後に(送信失敗メールがあったかにかかわらず)削除されます。
 * 作成された配信ファイルのステータスはすべて`none`となります。
 *
 * ・結果ファイルについて
 * メールを1件送信するごとに1行づつ追記していきます。
 * 送信成功したメールは、ステータス`done`として結果ファイルに記録されます。
 * 何らかの理由で送信失敗したメールは、ステータスを'error'として結果ファイルに記録されます。
 *
 * ・メール配信処理が途中で止まったときの再送
 * 配信ファイルが残っているので、結果ファイルにある行以降の配信先に対してメールを送る。
 *
 * ・配信失敗したメールの再送
 * 結果ファイルのステータスが`error`になっている配信先に対してメールを送る。
 *
 * @package Plugin\MailMagazine\Service
 */
class MailMagazineService
{
    // ====================================
    // 定数宣言
    // ====================================
    const REPOSITORY_SEND_HISTORY = 'eccube.plugin.mail_magazine.repository.mail_magazine_send_history';
    const REPOSITORY_SEND_CUSTOMER = 'eccube.plugin.mail_magazine.repository.mail_magazine_send_customer';

    // send_flagの定数
    /** メール未送信 */
    const SEND_FLAG_NONE = 0;
    /** メール送信成功 */
    const SEND_FLAG_SUCCESS = 1;
    /** メール送信失敗 */
    const SEND_FLAG_FAILURE = 2;

    // ====================================
    // 変数宣言
    // ====================================
    /** @var \Eccube\Application */
    public $app;

    /**
     * 最後の送信者に送信したメールの本文(テキスト形式)
     * @var string
     */
    private $lastSendMailBody = "";

    /**
     * 最後の送信者に送信したメールの本文(HTML形式)
     * @var string
     */
    private $lastSendMailHtmlBody = '';

    /** @var string */
    private $mailMagazineDir;

    /** @var \Eccube\Entity\BaseInfo */
    public $BaseInfo;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->BaseInfo = $app['eccube.repository.base_info']->get();
        $this->mailMagazineDir = str_replace(
            '${ROOT_DIR}',
            $app['config']['root_dir'],
            $this->app['config']['MailMagazine']['const']['mail_magazine_dir']);
        if (!file_exists($this->mailMagazineDir)) {
            mkdir($this->mailMagazineDir);
        }
    }

    /**
     * メールを送信する
     * @param array $formData メルマガ情報
     *                  email: 送信先メールアドレス
     *                  subject: 件名
     *                  body：本文
     */
    protected function sendMail($formData) {
        // メール送信
        /** @var \Swift_Message $message */
        $message = \Swift_Message::newInstance()
            ->setSubject($formData['subject'])
            ->setFrom(array($this->BaseInfo->getEmail01() => $this->BaseInfo->getShopName()))
            ->setTo(array($formData['email']))
            //             ->setBcc($this->BaseInfo->getEmail01())
            ->setReplyTo($this->BaseInfo->getEmail03())
            ->setReturnPath($this->BaseInfo->getEmail04())
            ->setBody($formData['body']);

        if ($formData['htmlBody']) {
            $message->addPart($formData['htmlBody'], 'text/html');
        }

        return $this->app->mail($message);
    }

    /**
     * 配信履歴の作成。
     *
     * 配信履歴データ(MailMagazineSendHistory)の作成と、配信履歴ファイルを作成します。
     *
     * @param array $formData
     * @return int 採番されたsend_id
     *         エラー時はfalseを返す
     */
    public function createMailMagazineHistory($formData) {

        // メール配信先リストの取得
        /** @var MailMagazineCustomerRepository $mailMagazineCustomerRepository */
        $mailMagazineCustomerRepository = $this->app['eccube.plugin.mail_magazine.repository.mail_magazine_customer'];
        $mailMagazineCustomerRepository->setApplication($this->app);
        $customerList = $mailMagazineCustomerRepository->getCustomerBySearchData($formData);

        $currentDatetime = new \DateTime();

        // -----------------------------
        // dtb_send_historyを登録する
        // -----------------------------
        $sendHistory = new MailMagazineSendHistory();

        // 登録値を設定する
        $sendHistory->setBody($formData['body']);
        $sendHistory->setHtmlBody($formData['htmlBody']);
        $sendHistory->setSubject($formData['subject']);
        $sendHistory->setSendCount(count($customerList));
        $sendHistory->setCompleteCount(0);
        $sendHistory->setErrorCount(0);
        $sendHistory->setDelFlg(Constant::DISABLED);

        $sendHistory->setEndDate(null);
        $sendHistory->setUpdateDate(null);

        $sendHistory->setCreateDate($currentDatetime);
        $sendHistory->setStartDate($currentDatetime);

        // Formから検索条件を取得し、シリアライズする(array)
        // 事前に不要な項目は削除する
        unset($formData['pageno']);
        unset($formData['pagemax']);
        unset($formData['id']);
        unset($formData['subject']);
        unset($formData['body']);

        // serializeのみだとDB登録時にデータが欠損するのでBase64にする
        $sendHistory->setSearchData(base64_encode(serialize($formData)));

        $status = $this->app[self::REPOSITORY_SEND_HISTORY]->createSendHistory($sendHistory);
        if(!$status) {
            return null;
        }

        $sendId = $sendHistory->getId();
        $fp = fopen($this->getHistoryFileName($sendId), "w");
        foreach($customerList as $customer) {
            fwrite($fp, self::SEND_FLAG_NONE.','.$customer['email'].','.$customer['name01'] . ' ' . $customer['name02'].PHP_EOL);
        }
        fclose($fp);

        return $sendId;
    }

    /**
     * 履歴ファイルを結果ファイルにマージする。
     * 例)
     * ・履歴ファイル
     * none,aaa@example.com,aaa
     * none,bbb@example.com,bbb
     * none,ccc@example.com,ccc
     *
     * ・結果ファイル
     * done,aaa@example.com,aaa
     *
     * ・マージした結果ファイル
     * done,aaa@example.com,aaa
     * none,bbb@example.com,bbb
     * none,ccc@example.com,ccc
     *
     * @param string|$fileHistory 履歴ファイル
     * @param string|$fileResult 結果ファイル
     */
    private function mergeHistoryFile($fileHistory, $fileResult)
    {
        // 結果ファイルのバイト数
        $resultBytes = filesize($fileResult);

        // 結果ファイルのバイト数分、履歴ファイルを読み飛ばす
        $fin = fopen($fileHistory, 'r');
        fseek($fin, $resultBytes);

        // 残りの履歴ファイルの内容を結果ファイルに追記する
        $fout = fopen($fileResult, 'a');
        while ($line = fgets($fin)) {
            fwrite($fout, $line);
        }

        fclose($fin);
        fclose($fout);
    }

    public function markRetry($sendId)
    {
        // 再送時の前処理
        $fileHistory = $this->getHistoryFileName($sendId);
        $fileResult = $this->getHistoryFileName($sendId, false);

        // 履歴ファイルと結果ファイルが残っている場合 -> 未配信メールが残っている
        // 履歴ファイルを結果ファイルにマージしてマージしたファイルを履歴ファイルとしてメール配信する。
        if (file_exists($fileHistory) && file_exists($fileResult)) {
            $this->mergeHistoryFile($fileHistory, $fileResult);
            rename($fileResult, $fileHistory);
        }
        // 履歴ファイルは削除されていて、結果ファイルだけある場合 -> 送信失敗したメールを再送する
        // 結果ファイルを履歴ファイルとしてメール配信する。
        else if (!file_exists($fileHistory) && file_exists($fileResult)) {
            rename($fileResult, $fileHistory);
        }
    }

    /**
     * Send mailmagazine.
     * メールマガジンを送信する.
     *
     * @param int $sendId 履歴ID
     * @param int $offset 開始位置
     * @param int $max 最大数
     * @return MailMagazineSendHistory 送信履歴
     */
    public function sendrMailMagazine($sendId, $offset = 0, $max = 100)
    {
        // send_historyを取得する
        /** @var MailMagazineSendHistory $sendHistory */
        $sendHistory = $this->app[self::REPOSITORY_SEND_HISTORY]->find($sendId);

        if(is_null($sendHistory)) {
            // 削除されている場合は終了する
            return false;
        }

        if ($offset == 0) {
            $this->markRetry($sendId);
        }

        // エラー数
        $errorCount = $offset > 0 ? $sendHistory->getErrorCount() : 0;

        // 履歴ファイルと結果ファイル
        $fileHistory = $this->getHistoryFileName($sendId);
        $fileResult = $this->getHistoryFileName($sendId, false);
        $handleHistory = fopen($fileHistory, "r");

        // スキップ数
        $skipCount = $offset;
        // 処理数
        $processCount = 0;

        while ($line = fgets($handleHistory)) {

            if ($skipCount-- > 0) {
                continue;
            }

            if ($processCount >= $max) {
                break;
            }

            list($status, $email, $name) = explode(",", $line, 3);


            if ($status == self::SEND_FLAG_SUCCESS) {
                $handleResult = fopen($fileResult, "a");
                fwrite($handleResult, $line);
                fclose($handleResult);
                $processCount++;
                continue;
            }

            $body = preg_replace('/{name}/', $name, $sendHistory->getBody());
            $htmlBody = preg_replace('/{name}/', $name, $sendHistory->getHtmlBody());
            // 送信した本文を保持する
            $this->lastSendMailBody = $body;
            $this->lastSendMailHtmlBody = $htmlBody;
            $mailData = array(
                'email' => $email,
                'subject' => preg_replace('/{name}/', $name, $sendHistory->getSubject()),
                'body' => $body,
                'htmlBody' => $htmlBody
            );
            try {
                $sendResult = $this->sendMail($mailData);
            } catch(\Exception $e) {
                log_error($e->getMessage());
                $sendResult = false;
            }

            // メール送信成功時
            $handleResult = fopen($fileResult, "a");
            if($sendResult) {
                fwrite($handleResult, self::SEND_FLAG_SUCCESS.','.$email.','.$name);
            }
            // メール送信失敗時
            else {
                fwrite($handleResult, self::SEND_FLAG_FAILURE.','.$email.','.$name);
                $errorCount++;
            }
            fclose($handleResult);

            $processCount++;
        }
        fclose($handleHistory);

        // 全部終わったら履歴ファイルを削除
        if ($offset + $processCount >= $sendHistory->getSendCount()) {
            $errorCount = 0;
            $handleResult = fopen($fileResult, "r");
            while ($line = fgets($handleResult)) {
                if (substr($line, 0, 1) == self::SEND_FLAG_FAILURE) {
                    $errorCount++;
                }
            }
            fclose($handleResult);
            unlink($fileHistory);
        }

        // 送信結果情報を更新する
        $sendHistory->setEndDate(new \DateTime());
        $sendHistory->setCompleteCount($offset > 0 ? $sendHistory->getCompleteCount() + $processCount : $processCount);
        $sendHistory->setErrorCount($errorCount);
        $this->app[self::REPOSITORY_SEND_HISTORY]->updateSendHistory($sendHistory);

        return $sendHistory;
    }

    /**
     * 送信完了報告メールを送信する
     *
     * @return number
     */
    public function sendMailMagazineCompleateReportMail() {

        $subject = date('Y年m月d日H時i分') . '　下記メールの配信が完了しました。';

        $mailData = array(
                'email' => $this->BaseInfo->getEmail03(),
                'subject' => $subject,
                'body' => $this->lastSendMailBody,
                'htmlBody' => $this->lastSendMailHtmlBody
        );

        try {
            return $this->sendMail($mailData);
        } catch (\Exception $e) {
            log_error($e->getMessage());
            return false;
        }
    }

    public function getHistoryFileName($historyId, $input = true)
    {
        return $this->mailMagazineDir.'/mail_magazine_'.($input ? 'in' : 'out').'_'.$historyId.'.txt';
    }

    public function unlinkHistoryFiles($historyId)
    {
        foreach (array($this->getHistoryFileName($historyId), $this->getHistoryFileName($historyId, false)) as $f)
        {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }
}
