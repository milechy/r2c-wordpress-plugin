# R2C AI Concierge — WordPress プラグイン

このリポジトリは R2C（`milechy/commerce-faq-tasks`）の WordPress プラグイン配布物。
**要件の一次資料はこのリポジトリではなく `commerce-faq-tasks` の `docs/WORDPRESS_PLUGIN_REQUIREMENTS.md`。**
着手前に必ずそちらを読むこと（このファイルはそこから、プラグイン実装者が繰り返し事故りやすい点だけを抜粋している）。

## この分離の理由（D8）

wordpress.org が求めるのは開発リポジトリへのリンクのみで、サーバコード（`commerce-faq-tasks`）の公開は不要。
本体リポジトリに GPLv2 のディレクトリを混ぜるとライセンス境界が曖昧になるため、ここを独立させている。

## 絶対にやってはいけないこと（wordpress.org 掲載が取り消される）

1. **プラグイン側で Powered by バッジ・広告帯を出力する。** ガイドライン#10 の但し書きにより、ブランディングは
   R2C サービス側（`widget.js` が配信するコード）でのみ許可される。プラグイン（このリポジトリ）のコードに
   1行でも描画ロジックを入れた瞬間に違反になる。
2. **支払い状態で分岐する機能ロックをコードに置く。** ガイドライン#5（トライアルウェア禁止）。プラン差は
   R2C サービス側のみで表現する。「Pro で解除」のような文言・分岐をこのリポジトリに書かない。
3. **未接続状態で外部通信する。** ガイドライン#7。`api.r2c.biz` への通信は、利用者が同意チェックを入れて
   接続ボタンを押した後にのみ発火させる。設定画面を開いただけで通信しない。
4. **設定画面を `admin.r2c.biz` の iframe にする。** ガイドライン#8 が admin ページの iframe を名指しで禁止。
   REST API（wp_remote_*）で構成する。
5. **静的な埋め込み（`data-tenant` 直書き）へ流す。** 動的配信ルート（`api.r2c.biz/widget/{tenantId}.js`）
   だけを使う。静的版はプラン判定を経由しない fail-open 経路。
6. **設定値をこのプラグイン側で権威として持つ。** 位置・除外ページ・許可ドメインの真実は常に R2C 側 DB。
   `wp_options` はキャッシュであって権威ではない。設定画面を開いたら毎回 R2C から現在値を取得して表示し、
   変更は即座に R2C へ PATCH する（ローカル保存→後で同期、をしない）。
7. **難読化・minify したコードをコミットする。** ガイドライン#4。ビルド成果物ではなく、常に人間が読める
   ソースをコミットする。

## 命名・構成

- 関数・クラス・option 名・REST ルート名はすべて `r2c_` プレフィックス（WP はグローバル名前空間が共有のため）。
- slug は `r2c-ai-concierge` で確定（後から変更できない）。
- 全 PHP ファイル冒頭に `defined( 'ABSPATH' ) || exit;`。
- 全フォームに nonce、全管理操作に `current_user_can( 'manage_options' )`。
- 入力は `sanitize_*`、出力は `esc_*`。
- `uninstall.php` はこのプラグインが作成した option を必ず全削除する。新しい option を足したら同じコミットで
  `uninstall.php` にも削除処理を足すこと。

## コーディング規約

`composer install && composer run lint`（PHPCS + WordPress Coding Standards + PHPCompatibilityWP、`phpcs.xml.dist` 参照）。
PHP 7.4 以上を宣言しているので、`testVersion` もそれに合わせている。

## 対応する要件書のタスク

WP-6（このリポジトリの骨格。完了）／ WP-7（設定画面）／ WP-8（ウィジェット出力）／ WP-9（未接続ゼロ通信のテスト）／
WP-10（readme.txt の完成、External Services 節）。サーバ側（プロビジョニングAPI・設定API）は
`commerce-faq-tasks` にあり、そちらは別リポジトリで開発が進む。
