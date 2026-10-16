# AI Cover Image for Joomla

Make the cover image of an article from the article itself. AI Cover Image reads the title, the text
and the tags, asks a language model for one clear visual metaphor, and has an image model draw it in
the style you chose. An **AI image** button appears next to the image fields of the article form, for
Joomla's own intro and full-article images, custom image fields, and FLEXIcontent image fields. The
panel shows the result, takes requests for changes, keeps the versions, and writes the one you pick
into the field.

Image services: OpenAI (gpt-image), Google Gemini (Nano Banana), or an
[Open WebUI](https://github.com/open-webui/open-webui) server you host, with whatever image engine
it is connected to.

> **Status: 2.0.0, first public release.** Used on three Joomla sites (4.4 with FLEXIcontent, 5.3
> with FLEXIcontent, 5.4) since September 2026, and checked against Joomla 6.1. Read
> [SECURITY.md](SECURITY.md) before you let every author generate images.

[繁體中文說明](#繁體中文)

## Features

- **One button next to every image field.** Joomla intro and full-article images, custom media
  fields, and FLEXIcontent image fields (database, folder and media manager modes).
- **A panel, not a one-shot.** Generate, ask for changes in your own words, generate again, compare
  the versions, apply the one you like. Revising sends the current image back to the model as the
  edit target; a fresh composition starts over.
- **The picture means something.** A language model reads the article first and proposes a single
  visual metaphor. The image model then draws that, not the words of the text.
- **Four layers of settings.** Defaults, then per image field, then per category (optionally for
  some fields only), then what the editor changes in the panel: extra prompt, aspect ratio, maximum
  width, layout mode, text in the image, which service to use. Extra prompts add up; sizes override.
- **Any ratio.** Write `16:9` or `1200x630`. The model draws the nearest ratio it supports, the plugin
  crops from the centre and scales down to the maximum width. Images are never enlarged.
- **Styles as skills.** A built-in editorial style with your brand colours, public skills from
  GitHub installed with one click, or your own style guide uploaded as a zip (`SKILL.md`, further
  documents, example images). Editors may switch styles per image when you allow it.
- **Reference images.** The administrator's folder of style examples goes with every request;
  editors can add up to three images of their own for one request. Those are never stored.
- **Limits that follow the user.** An hourly limit per user kept in the database, one image at a
  time per user, drafts removed the moment an image is applied.
- Traditional Chinese and English.

## Requirements

Joomla 4.2 or later (checked against 4.4, 5.3, 5.4 and 6.1), PHP 8.1 or later with the GD and zip
extensions, and a key for at least one image service.

## Installation

1. Download `pkg_gssaiimage-<version>.zip` from the [releases](../../releases), or build it with
   `./build.sh`.
2. Install it through **System, Install, Extensions**. Both plugins are enabled on install.
3. Open **System, Plugins, System - GSS AI Cover Image** and add a key under **API and services**.
4. For FLEXIcontent, the second plugin, **System - GSS AI Cover Image - FLEXIcontent**, is already
   enabled; set the names of its tag fields in its own settings if you have them.

## Getting an API key

| Service | Where | Notes |
| --- | --- | --- |
| OpenAI | <https://platform.openai.com/api-keys> | The account needs credit. The gpt-image models need completed organisation verification under Settings, Organization |
| Gemini | <https://aistudio.google.com/apikey> | Image generation needs a Google Cloud project with billing enabled |
| Open WebUI | Your own server: Settings, Account, API keys | The server administrator must first enable image generation under Admin Settings, Images, and connect an image engine there (OpenAI, Gemini, ComfyUI, Automatic1111 and others) |

Keys stay in the plugin parameters on the server and are never sent to the browser. Images are
billed one by one; set the hourly limit per user in the same tab.

## Settings

| Tab | What it holds |
| --- | --- |
| API and services | Default service; keys and models for OpenAI, Gemini and Open WebUI; timeout; hourly limit per user |
| Image fields | Per field: extra prompt, ratio, maximum width, layout mode, text in the image, service |
| Category rules | Per category, with or without its children: the same, optionally limited to some fields |
| Output | Default ratio and width, whether editors may change the size, file format and quality, save folder, how long drafts are kept |
| Visual style | The style skill (built-in, from the list of sources, or uploaded), whether editors may switch styles, layout mode, text in the image, brand colours, reference images, extra style direction, things to avoid, the picture idea step |
| Joomla image fields | Where the button appears, the name of the tags field |

Field names: the intro image is `image_intro` and the full-article image is `image_fulltext`. For
custom fields and FLEXIcontent fields use the field's name, for example `og_image`. The panel shows
the name of the current field under **Settings in use**.

## Open WebUI

The plugin uses `POST /api/v1/images/generations` for images from a prompt alone,
`POST /api/v1/images/edit` when reference images go with the request or an image is revised,
`GET /api/v1/files/{id}/content` to fetch the result, and `POST /api/v1/chat/completions` for the
picture idea. Checked against Open WebUI 0.11.4:

- Images from a prompt alone always use the image model set on the server; the model named in the
  plugin applies only on the edit route.
- The edit route gets exactly one image. Several reference images are composed into one sheet first,
  and the prompt says so. When an image is revised, only that image is sent.
- When the server does not allow image editing, a new image is made from the prompt alone and the
  panel says that the references were not used.
- The key is sent to the configured server only, and a file address in the answer is fetched only
  when it is on that server.

## Styles

The built-in style is a general editorial illustration style that belongs to no brand: a light or
dark gradient field with fine noise, a restrained palette, flat shapes and paper collage, people only
as cropped grayscale fragments, AI as nodes and flows rather than robots. Enter your brand colours
under **Visual style**, and point **Own reference folder** at a folder below `images/` with a few
example images.

For your own visual language, write a skill: a zip with a `SKILL.md` (optionally with `name` and
`description` in its front matter), further Markdown, text or YAML documents, and example images.
Upload it in the **Visual style** tab. A language model then writes the prompt by that skill, and
the plugin adds the rules that always hold: no text unless asked, the crop, the ratio. The tab also
lists public image skills on GitHub that install with one click; each is pinned to one commit and
fetched file by file.

By default images carry no text. With **Title in the image**, the headline is quoted verbatim;
check every character before you apply.

## Development

```
plg_system_gssaiimage/        core and Joomla image fields
plg_system_gssaiimageflexi/   FLEXIcontent image fields
pkg_gssaiimage/               package manifest and installer
build.sh                      builds dist/pkg_gssaiimage-<version>.zip
tests/                        tests; see tests/README.md
```

The unit tests need PHP with GD and zip and no Joomla site; the `joomla_*.php` scripts run against a
local site from the command line. Pushing a tag `v<version>` builds the package and publishes a
release through GitHub Actions.

## Security

See [SECURITY.md](SECURITY.md) for what to know before you install and how to report a problem, and
[SECURITY-AUDIT.md](SECURITY-AUDIT.md) for what has been reviewed.

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).

## 繁體中文

依文章的標題、內文與產品標籤，用 OpenAI（gpt-image）、Gemini Nano Banana 或你自己架設的 Open WebUI 伺服器產生文章主題圖片。
風格由內建的編輯插畫風格、推薦來源的 skill 或自己上傳的 skill 決定。
圖片欄位旁會出現「AI 生圖」按鈕，點開是一個大型提示面板。產生後可以繼續提出需求、重新產生，滿意再套用到欄位。

| 外掛 | 用途 |
| --- | --- |
| 系統 - GSS AI 主題圖片（`plg_system_gssaiimage`） | 核心設定、分類規則、生圖，以及 Joomla 原生圖片欄位 |
| 系統 - GSS AI 主題圖片 - FLEXIcontent（`plg_system_gssaiimageflexi`） | FLEXIcontent 圖片欄位。需要核心外掛一起啟用 |

需求：Joomla 4.2 以上（已對照 4.4 與 5.4 的原始碼）、PHP 8.1 以上、GD。

授權：GNU General Public License 第 2 版或之後的版本，全文見各外掛資料夾的 `LICENSE`。

### 安裝

1. 在「系統 → 安裝 → 擴充套件」上傳 `pkg_gssaiimage.zip`。第一次安裝會自動啟用兩個外掛。
2. 開啟「系統 - GSS AI 主題圖片」外掛，在「API 與服務」填入 OpenAI、Gemini 或 Open WebUI 的設定。填了不只一個，編輯者就能在面板中切換。
3. 在「圖片欄位設定」與「分類規則」替需要的欄位與分類加上額外的 prompt、比例與最大寬度。
4. 沒有 FLEXIcontent 的站台可以停用 FLEXIcontent 外掛。

### 設定

| 頁籤 | 內容 |
| --- | --- |
| API 與服務 | 預設服務，OpenAI、Gemini 與 Open WebUI 的金鑰與模型，等待秒數上限、每人每小時次數上限 |
| 圖片欄位設定 | 每一列：欄位名稱（可多個）、額外的 prompt、比例、最大寬度、版面模式、圖中文字、生圖服務 |
| 分類規則 | 每條規則：分類（可多選）、是否套用到子分類、適用的圖片欄位、額外的 prompt、比例、最大寬度、版面模式、圖中文字、生圖服務 |
| 輸出 | 預設比例與最大寬度、編輯者能否調整尺寸、檔案格式、壓縮品質、儲存資料夾、草稿保留時數 |
| 視覺風格 | 風格 Skill 的選擇、安裝與上傳、編輯者能否切換風格、版面模式、圖中文字、風格參考圖、編輯者能否提供參考圖、補充風格方向、額外避免的元素、畫面構想 |
| Joomla 圖片欄位 | 前後台開關、產品標籤欄位名稱 |

#### 取得 API 金鑰

外掛至少要有一個生圖服務的金鑰。金鑰只存在伺服器端的外掛參數裡，不會送到瀏覽器。

| 服務 | 到哪裡取得 | 要注意的事 |
| --- | --- | --- |
| OpenAI | <https://platform.openai.com/api-keys> 建立金鑰，以 `sk-` 開頭 | 帳號要先在 Billing 儲值。gpt-image 系列模型需要在 Settings → Organization 完成組織驗證，否則 API 會回覆模型不可用 |
| Gemini | <https://aistudio.google.com/apikey> 建立金鑰 | 生圖模型需要已啟用計費的 Google Cloud 專案。免費方案通常不開放圖片產生 |
| Open WebUI | 登入自己架設的 Open WebUI，到「設定 → 帳號 → API 金鑰」建立 | 伺服器管理員要先在「管理員設定 → 圖片」啟用圖片產生，並接上一個生圖服務。見下一節 |

生圖會逐張計費，建議在「API 與服務」頁籤設定每人每小時的次數上限。

#### 使用 Open WebUI

如果已經有自己架設的 [Open WebUI](https://github.com/open-webui/open-webui)，可以直接用它接好的模型，不必在這裡另外填 OpenAI 或 Google 的金鑰。
Open WebUI 的管理員要先做兩件事：

1. 在「管理員設定 → 圖片」啟用圖片產生，選一個生圖引擎（OpenAI、Gemini、ComfyUI、Automatic1111 等）並填入該引擎的金鑰與預設模型。
2. 在「管理員設定 → 使用者 → 群組」確認外掛使用的帳號有圖片產生的權限。

| 欄位 | 填什麼 |
| --- | --- |
| 伺服器位址 | Open WebUI 的網址，例如 `https://ai.example.com`。貼上含 `/api/v1` 的位址也可以 |
| Open WebUI API 金鑰 | 在 Open WebUI 的「設定 → 帳號 → API 金鑰」建立 |
| 生圖模型 | Open WebUI 管理員設定的生圖模型代號，例如 `gpt-image-1`。留空使用伺服器預設的生圖模型 |
| 語言模型 | Open WebUI 模型清單裡的模型代號，例如 `gpt-4o-mini`。留空時不產生畫面構想 |
| 取回後刪除伺服器上的檔案 | 預設開啟。Open WebUI 會把每張圖存在帳號的檔案裡，外掛取回後會刪除 |

填好後把「預設服務」改成 Open WebUI，或在圖片欄位設定、分類規則中指定。

外掛使用的端點：

| 動作 | 端點 |
| --- | --- |
| 只依文字產生 | `POST /api/v1/images/generations` |
| 附上參考圖，或修改既有圖片 | `POST /api/v1/images/edit`，一律只送一張圖 |
| 取回圖片 | `GET /api/v1/files/{id}/content` |
| 畫面構想 | `POST /api/v1/chat/completions` |

需要注意的行為，已對照 Open WebUI 0.11.4 的原始碼：

- **只依文字產生時，模型由伺服器決定**。Open WebUI 的這個端點不採用要求裡的模型名稱，一律使用管理員設定的生圖模型。外掛填的生圖模型只在附上參考圖或修改圖片時生效。
- **外掛預設會附上風格參考圖**，所以一般情況下走的是可以指定模型的那個端點。
- **一律只送一張圖**。Open WebUI 雖然接受圖片陣列，但轉給後面的模型時會被拒絕。多張參考圖會先合併成一張再送出，prompt 會說明這是幾張參考圖。
- **修改圖片時只送要修改的那張**。這時編輯者另外加入的參考圖不會被採用，面板會提示。
- **語言模型的要求不帶 `response_format`**。部分模型會拒絕它，回覆仍以 JSON 解讀。
- **伺服器沒有開放圖片編輯時**，新圖會改成只依文字產生，面板會提示參考圖沒有被使用。「修改這張圖」則無法使用，要改選「重新構圖」。
- **金鑰只會送到設定的伺服器**。伺服器回傳的檔案位址若指向別的主機，外掛不會去抓。

以 Open WebUI 0.11.4 搭配 OpenAI 生圖引擎實測的結果：

| 項目 | 結果 |
| --- | --- |
| 畫面構想 | 成功，約 3 秒 |
| 附兩張風格參考圖產生 1536x1024 | 成功，約 45 秒 |
| 修改既有圖片 | 成功，約 35 到 45 秒 |
| 取回圖片與刪除伺服器上的檔案 | 成功 |
| 圖片以陣列傳送 | 被拒絕，HTTP 400。所以外掛改成只送一張 |

#### 設定的四層覆蓋

每張圖的比例、最大寬度、版面模式、圖中文字與生圖服務，依下列順序決定。上面的層有填值就蓋過下面的層，留空就沿用下面的層。

| 層 | 在哪裡設定 | 說明 |
| --- | --- | --- |
| 4. 編輯當下 | 面板的「尺寸與風格」 | 編輯者這一次的調整。可在「輸出」頁籤關閉 |
| 3. 分類 | 「分類規則」頁籤 | 依文章的分類 |
| 2. 圖片欄位 | 「圖片欄位設定」頁籤 | 依按下按鈕的那個圖片欄位 |
| 1. 全部預設 | 「輸出」與「視覺風格」頁籤 | 其他層都沒有設定時使用 |

額外的 prompt 不互相取代。圖片欄位的與分類的會一起套用，編輯者在面板寫的需求再加在後面。

欄位名稱的寫法：

- 前言圖片是 `image_intro`，全文圖片是 `image_fulltext`。
- Joomla 自訂欄位與 FLEXIcontent 欄位填欄位的名稱，例如 `og_image`、`hero_image`。
- 面板的「套用設定」會顯示目前欄位的名稱，可以直接照抄。把滑鼠移到比例或寬度上，會顯示這個值來自哪一層。

分類規則的比對方式：文章所在分類有規則就用它。沒有時往上找父分類中開啟「套用到子分類」的規則。
同一個分類有多條規則時，優先使用「適用的圖片欄位」包含目前欄位的規則，其次才是適用所有欄位的規則。
FLEXIcontent 使用的是 Joomla 的分類，所以兩個外掛共用同一份規則，FLEXIcontent 以主分類比對。

例子：OG 圖片要固定 1200x630，客戶故事的其他圖片要 3:2。在「圖片欄位設定」加一列 `og_image`，比例填 `1200x630`。在「分類規則」替客戶故事加一條規則，比例填 `3:2`，「適用的圖片欄位」填 `hero_image`。這樣 OG 圖片不會被分類的比例蓋掉。

比例可以寫 `16:9`，也可以寫 `1200x630`。模型只能畫固定幾種比例，外掛會先用最接近的比例產生，再從中央裁切成指定比例，並縮小到最大寬度。圖片不會被放大。

### 使用

1. 在文章或項目的編輯畫面填好標題、內文、分類與標籤。不需要先儲存。
2. 點圖片欄位旁的「AI 生圖」。面板上方會列出參考內容，以及這個欄位與分類套用的設定、比例與最大寬度。
3. 需要時填寫補充需求，也可以加入自己的參考圖，然後按「產生圖片」。
4. 看到圖片後，在「想怎麼調整？」寫下需求，再按「重新產生」：
   - **修改這張圖**：把目前的圖片連同需求送回模型，只改提到的地方。
   - **重新構圖**：依文章與所有提過的需求重畫一張。已提出的需求會列在上方，可以個別移除。
5. 每次產生都會留下一個版本，可以點縮圖切換。按「套用到欄位」把選中的版本放進欄位，儲存文章後生效。
6. 套用之後，這個欄位的草稿與版本會立即清除。再次開啟面板會從文章重新開始。

### 編輯者的參考圖

面板中的「參考圖片」可以加入最多 3 張圖，例如產品畫面、實物照片或想參考的構圖。可以用選擇檔案、拖曳到面板或貼上的方式加入。

- 參考圖會隨「產生圖片」與「重新產生」一起送給模型。模型依它決定要畫什麼，再用所選的風格重畫，不會直接貼上。
- 面板一關閉就清空，包含按關閉、按 Esc、點面板外面，以及套用到欄位之後。已產生的版本仍會保留。
- 參考圖不會存到網站。瀏覽器先把圖縮到長邊 1536 px 以內再上傳，伺服器重畫成 JPEG 放在暫存資料夾，該次要求結束就刪除。
- 接受 JPEG、PNG 與 WebP。不想開放時，可以在「視覺風格」頁籤關閉「編輯者可提供參考圖」。

這與「視覺風格」頁籤的風格參考圖不同。風格參考圖由管理員設定，每次都會附上，用來維持風格一致。

### 視覺風格

風格由「視覺風格」頁籤最上方的「風格 Skill」管理。這裡分成三塊：

| 區塊 | 用途 |
| --- | --- |
| 預設風格 | 選擇沒有另外指定時使用的風格。內建的編輯插畫風格是預設值 |
| 推薦來源 | GitHub 上公開、授權明確的生圖 skill，可以一鍵安裝 |
| 上傳自己的 skill | 上傳 zip 壓縮檔 |

安裝或上傳的 skill 會出現在「預設風格」的清單中。開啟「編輯者可切換風格」時，編輯者也能在面板的「尺寸與風格」裡替每一張圖選擇風格。

#### 推薦來源

| 來源 | 內容 | 授權 |
| --- | --- | --- |
| GPT Image 2 風格預設，glebis | 二十多種現成的風格預設，例如線稿、藍圖、水墨、孔版印刷、包浩斯 | MIT |
| GPT Image 2 模板庫，ConardLi | 中文的結構化提示詞模板，收錄封面、橫幅、品牌海報與場景插畫 | MIT |
| Banana 視覺簡報與提示詞方法，AgriciDaniel | 把需求整理成視覺簡報再寫成提示詞的方法，沒有固定風格 | MIT |
| 白板手繪資訊圖，charlie947 | 像拍下白板的手繪資訊圖 | MIT |
| Go Bananas 攝影與行銷提示詞，davendra | 偏攝影與產品行銷的提示詞寫法 | MIT |

安裝時的規則：

- **只取文字文件**。不下載任何程式、指令檔或設定檔，也不會執行來源裡的任何東西。
- **固定在審閱過的那一版**。每個來源都指定到 GitHub 上的一個 commit，之後來源的內容變動不會影響已列出的版本。
- **只能安裝清單上的來源**。要求中只帶清單的代號，不接受任意網址。主機需要能連到 `raw.githubusercontent.com`。
- **這些 skill 原本是寫給會執行工具的助理用的**。外掛只把它們當成風格與提示詞寫法的知識，並要求語言模型略過工具、指令與提問的部分。
- **內容越多的 skill，每次產生時語言模型讀的字越多**，費用與等待時間也略高。

含多種風格的來源，語言模型會挑最適合文章的一種。想指定時，在「補充需求」寫明即可，例如「用 blueprint 風格」。

#### 上傳 skill

1. 在「風格 Skill」按「上傳 skill 壓縮檔」，選擇 zip 檔。也可以把檔案拖曳到清單上。
2. 上傳後會出現在清單中並自動選取。同名的 skill 會被新的檔案取代，適合用來更新版本。
3. 按「儲存」後，選用的風格才會生效。

上傳與刪除會立即生效，不必儲存。上傳前外掛必須是啟用狀態。

#### 壓縮檔的內容

| 路徑 | 用途 | 必要 |
| --- | --- | --- |
| `SKILL.md` | 風格的主要說明。開頭的 `name` 與 `description` 會顯示在清單中 | 是 |
| `.md`、`.txt`、`.yaml` 文件 | 補充文件，例如風格系統、prompt 範本與風格預設 | 否 |
| `assets/*.jpg`、`*.png`、`*.webp` | 風格參考圖 | 否 |

`SKILL.md` 可以放在壓縮檔的最上層，或放在一層資料夾裡。其他種類的檔案一律忽略，不會寫到網站上。

#### 外掛如何使用 skill

- 語言模型會讀完 skill 的全部文件與文章內容，依 skill 寫出給生圖模型的 prompt，並從 skill 的參考圖中挑選最合適的幾張。
- 比例、圖中文字、欄位與分類的額外 prompt、編輯者的需求，會固定加在 prompt 後面，確保一定被遵守。
- 「先產生畫面構想」關閉，或語言模型沒有回應時，改把 skill 的內容直接交給生圖模型。效果通常較差。
- 選用的 skill 被刪除時，自動改用內建風格，不會出錯。

#### 儲存與安全

- skill 存在 `media/gssaiimage-skills/{名稱}/`。更新或重新安裝外掛不會動到它。
- 文件內容存在無法從網址讀取的檔案中。參考圖會重新繪製成 JPEG 並縮到長邊 1280 px 以內。
- 只有具備外掛編輯權限的後台使用者可以上傳與刪除。
- 檔案大小受主機的 PHP 上傳上限限制，設定畫面會顯示目前的上限。

#### 內建的編輯插畫風格

不屬於任何品牌的通用風格，不帶參考圖：

- 淺色或深色漸層底、細緻噪點、克制的配色、平面向量形狀與紙張拼貼。人物只以裁切的灰階局部出現，AI 以節點與資料流表現，不畫機器人與大腦。
- 四種版面模式：淺色漸層、編輯式拼貼、深色、幾何抽象。選「自動」時由語言模型依文章內容決定。
- 「品牌色」填入自家的色碼（最多 8 個，例如 `#1A5BFF, #7C3AED`），prompt 會要求只使用這些顏色與其淡色。留空時由模型自行選用克制的配色。
- 「自訂參考圖資料夾」填 `images` 底下的資料夾（例如 `images/style-reference`），放幾張符合自家風格的範例圖，每次會依檔名順序附上。

要完整套用自家的視覺語言，請把品牌的風格指南整理成 skill 上傳，見「上傳 skill」。

不論使用哪一種風格，預設都產生無文字的圖片。選「放入標題」時會逐字引用標題，請在套用前檢查每個中文字。

### FLEXIcontent 圖片欄位

| 欄位的圖片來源 | 套用時的處理 |
| --- | --- |
| 資料庫模式 | 存入 FLEXIcontent 檔案管理的資料夾，並在 `#__flexicontent_files` 登記 |
| 資料夾模式 | 存入 `{dir}/item_{項目}_field_{欄位}/original` |
| 媒體管理模式 | 存入核心外掛的儲存資料夾，以 Joomla 媒體欄位的格式寫入 |
| 前言 / 全文圖片 | 這種欄位沒有自己的值，請用前言圖片或全文圖片欄位旁的按鈕 |

縮圖由 FLEXIcontent 在儲存或顯示時自行產生。FLEXIcontent 外掛可以另外設定產品標籤欄位（例如 `product,service,summary`）與檔案格式，預設存成 JPEG。

### 檔案位置

| 路徑 | 內容 |
| --- | --- |
| `images/ai-generated/年/月/` | 已套用的圖片 |
| `media/gssaiimage-skills/{名稱}/` | 上傳的風格 skill |
| `images/ai-generated/_drafts/u{使用者編號}/` | 尚未套用的草稿。套用到欄位時立即刪除，沒有套用的超過保留時數後刪除 |

#### 草稿的清除

- 按「套用到欄位」後，這位編輯者的草稿會全部刪除，資料夾清空後一併移除。
- 同一頁上其他圖片欄位的面板若還有尚未套用的版本，那些草稿會保留，等該欄位套用時再刪除。
- 其他編輯者的草稿不受影響。
- FLEXIcontent 欄位套用後顯示的預覽是一張內嵌的小圖，不依賴草稿檔案。

### 安全性

1.5.0 依 2026-10-01 的安全性檢視修正了九個問題。相關的規則如下。

| 項目 | 規則 |
| --- | --- |
| 誰能使用 | 要先登入，並具備建立或編輯文章的權限。每個動作都要求 POST 與 Joomla 權杖 |
| 產生次數 | 每人每小時的上限記在資料庫，跟著使用者帳號。重新登入或同時送出多個要求都不會繞過 |
| 同時產生 | 每位編輯者同一時間只能產生一張 |
| 套用 | 一張草稿只能套用一次。套用次數與產生次數使用同一個上限 |
| 取代舊圖 | 只會刪除同一位編輯者在 24 小時內套用的檔案 |
| 參考圖 | 單張 6 MB、1200 萬像素以內，並先確認記憶體足夠才解開 |
| 儲存資料夾 | 只能是 images 或它底下的資料夾 |
| 錯誤訊息 | 編輯者只看到問題的種類。服務的原始訊息寫入記錄資料夾的 `plg_system_gssaiimage.php`，有外掛編輯權限的人也會在畫面上看到 |
| 執行時間 | 語言模型最多 45 秒，生圖最多為設定的等待秒數，上限 300 秒 |
| FLEXIcontent 新項目 | 只接受 FLEXIcontent 自己產生的暫時項目代號格式 |
| 上傳的 skill | 文字存檔時跳脫角括號，檔案內不會出現 PHP 標籤 |

使用紀錄存在 `#__user_profiles`，鍵值是 `gssaiimage.usage`，每位使用者一列。

還需要知道的三件事：

- 文章標題與內文、產品標籤、編輯者的參考圖會送到所選的生圖服務，未發布的文章也一樣。使用 Open WebUI 時，內容會先到你的 Open WebUI 伺服器，再由它轉給後面的模型。
- API 金鑰以明文存在外掛參數裡，這是 Joomla 外掛的慣例。
- 有建立或編輯文章權限的前台使用者也能產生圖片。

### 常見狀況

| 狀況 | 處理 |
| --- | --- |
| 面板顯示已經有一張圖片正在產生 | 同一位編輯者同時只能產生一張。若前一次要求被中斷，最多等幾分鐘就會自動解除 |
| 面板只顯示服務拒絕金鑰或沒有完成 | 原始訊息在記錄資料夾的 `plg_system_gssaiimage.php`。用有外掛編輯權限的帳號操作，畫面上也會顯示 |
| 面板顯示伺服器沒有在時間內回覆 | 生圖通常需要 30 到 90 秒。請把主機的 PHP `max_execution_time`，以及 Nginx、Apache 或 CDN 的逾時設定調到 180 秒以上 |
| OpenAI 回覆組織必須驗證 | gpt-image 模型需要在 OpenAI 平台完成組織驗證 |
| OpenAI 回覆金鑰不正確 | 1.2.0 之前的金鑰欄位最多 99 個字，較長的金鑰會被截斷。更新後請重新貼上金鑰並儲存 |
| 圖片比最大寬度小 | 模型輸出的寬度有上限（OpenAI 為 1536 px）。Gemini 的 Pro 模型可以在設定選 2K 或 4K |
| 圖片上緣或下緣的物件被切掉 | 指定的比例與模型支援的比例差距較大。可以在補充需求寫「主體集中在畫面中央」，或改用模型支援的比例 |

### 開發

```
plg_system_gssaiimage/        核心與 Joomla 圖片欄位
plg_system_gssaiimageflexi/   FLEXIcontent 圖片欄位
pkg_gssaiimage/               安裝包的設定檔與安裝程式
build.sh                      打包成 dist/pkg_gssaiimage-<版本>.zip
tests/                        測試，不會被打包
```

測試的執行方式見 `tests/README.md`。推送 `v<版本>` 的標籤時，GitHub Actions 會執行測試、打包並建立 release。
