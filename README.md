# Magento 2 MageAI Extension
This Magento 2 extension integrates **OpenAI (GPT)**, **Anthropic (Claude)**, and **Google Gemini** to automatically generate high-quality short and long product descriptions — and **AI-generated product images** — based on product attributes like name, features, material, and more. It's a powerful tool to save time and improve content quality across your catalog.

❤️ The goal of this extension is to remain fully open-source and continuously expand by integrating every possible way to use AI with Magento 2. From writing content to helping customers, improving SEO, or automating tasks — the idea is to make Magento and AI work great together. I'm building it to be flexible and helpful for everyone, and I'd love for others to join in. If you're into Magento or AI, your ideas and contributions are always welcome. Let's create something awesome together!

## Features
- **Multi-provider AI support** — switch between OpenAI (GPT), Anthropic (Claude), and Google Gemini from a single config screen
- **AI product image generation** — generate a product image from a prompt (or a configurable default) right from the Images And Videos section, added straight to the gallery (OpenAI & Gemini)
- Generate product descriptions using a **custom free-form prompt** for full control and flexibility
- Customize prompt templates using `{{ product.name }}` and `{{ product.attributes }}` variables
- Select **multiple product attributes** to base generation on (name, material, features, etc.)
- Configure **max tokens** and **temperature** separately for full and short descriptions
- Works on both **existing** and **unsaved (new) products**
- Supports OpenAI chat and completion endpoints (`gpt-4o`, `gpt-4-turbo`, `gpt-4o-mini`, `gpt-3.5-turbo`, etc.)
- Supports Anthropic Messages API (`claude-opus-4-5`, `claude-sonnet-4-6`, `claude-haiku-4-5`, etc.)
- Supports Google Gemini API (`gemini-2.5-flash`, `gemini-2.5-pro`, etc.)
- Clean, valid HTML output ready to use in the WYSIWYG editor
- Compatible with Page Builder

## Supported AI Providers

| Provider | Models |
|---|---|
| **Google Gemini** *(default)* | `gemini-2.5-flash`, `gemini-2.5-pro`, `gemini-1.5-flash`, `gemini-1.5-pro` |
| **OpenAI (ChatGPT)** | `gpt-4o`, `gpt-4o-mini`, `gpt-4-turbo`, `gpt-4`, `gpt-3.5-turbo` |
| **Anthropic (Claude)** | `claude-opus-4-5`, `claude-sonnet-4-6`, `claude-haiku-4-5` |

## Usage
1. Go to `Stores > Configuration > Mageprince > MageAI`
2. Choose your preferred AI **Provider** (Gemini, OpenAI, or Anthropic)
3. Enter the **API key** for the selected provider
4. Select the **model** and tune **max tokens** / **temperature** as needed
5. Open any product (new or existing) and click **"Generate with MageAI"** in the Short / Long Description editor
6. Watch the AI generate polished, HTML-ready content instantly
7. Save the product

### Custom Prompt
Click **"Advanced Generate with MageAI"** to open the custom prompt modal and type any free-form instruction — the module skips attribute lookup and sends your prompt directly to the AI.

### Generate Product Image
Open a product and click **"Generate Image with MageAI"** next to the **Add Video** button in the Images And Videos section. Enter a prompt (or leave it empty to use the default configured prompt) and the generated image is added straight to the product gallery. Available with the **OpenAI** and **Gemini** providers.

### Prompt Templates
Default prompts support two variables:
- `{{ product.name }}` — the product's name
- `{{ product.attributes }}` — a comma-separated `Label: Value` string from the attributes you selected in config

## How to Install
### Install via Composer

Run the following commands in your Magento 2 root folder:

```bash
composer require mageprince/magento2-mage-ai
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
```

## Contribution
Contributions are highly welcome and encouraged! 🙌

This project is a personal open-source effort to bring the power of AI to Magento 2. Whether you want to:
- Add new features
- Improve prompts or logic
- Fix bugs
- Help with translations or documentation
- Or just share feedback…

You're very welcome to join in.

**To contribute:**
1. Fork the repository
2. Create your feature branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -m 'Add new feature'`
4. Push to the branch: `git push origin feature/my-feature`
5. Create a new Pull Request

## Screenshots

### Generate Product Image
<img width="1280" height="720" alt="mageprince-mageai-image-generati" src="https://github.com/user-attachments/assets/b87f6d38-39e4-4a5c-afa4-8996f5790349" />

### Generate Descriptions with Custom Prompt
![mageprince-mageai-final](https://github.com/user-attachments/assets/9fbdad1b-5d27-4dbb-a3ca-274f3036ba34)

### Generate Product Short Description
![short-description](https://github.com/user-attachments/assets/a88899fc-d70a-4138-9295-e79b4efd1308)

### Generate Product Long Description With Page Builder
![full-description-short](https://github.com/user-attachments/assets/54031479-5d68-4af9-9fc1-c24680e4a858)

