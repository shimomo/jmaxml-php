# 気象庁防災情報XMLの公式資料

このディレクトリには、気象庁が公開している「気象庁防災情報XMLフォーマット」の技術資料を、配布物のまま（無加工で）置いています。コード生成（`tools/codegen`）とテストに使います。

出典：[気象庁ホームページ](https://xml.kishou.go.jp/)

利用にあたっては、気象庁ホームページの利用規約（政府標準利用規約（第2.0版）準拠）に従います。

| パス | 資料 | 版 | 取得元 |
|---|---|---|---|
| `xsd/*.xsd` | XMLスキーマファイル（xsd）一式（8 ファイル） | 2024-10-31 | https://xml.kishou.go.jp/jmaxml_20241031_Schema(xsd).zip |
| `dictionary.xlsx` | 辞書一式 | 2026-01-29 | https://xml.kishou.go.jp/jmaxml_20260129_dictionary.xlsx |
| `samples/*.xml` | サンプルデータ（xml）。かな漢字電文（`*_kana.txt`）は含めていません（546 ファイル） | 2026-09-17 | https://xml.kishou.go.jp/jmaxml_20260917_Samples.zip |
| `codes/地震火山関連コード表.xlsx` | 個別コード表のうち地震火山関連（zip 内のこのファイルだけ） | 2026-10-19 | https://xml.kishou.go.jp/jmaxml_20261019_Code.zip |

資料が更新されたときは、上の取得元の最新版に置き換え、この表の版を更新してから `composer generate` を実行してください。
