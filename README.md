# Magento 2 MageAI Extension
This Magento 2 extension uses OpenAI’s GPT models (ChatGPT) to automatically generate high-quality short and long product descriptions based on product attributes like name, features, material, etc. It’s a powerful tool to save time and improve the content quality across your catalog.

❤️ The goal of this extension is to remain fully open-source and continuously expand by integrating every possible way to use AI with Magento 2. From writing content to helping customers, improving SEO, or automating tasks — the idea is to make Magento and AI work great together. I’m building it to be flexible and helpful for everyone, and I’d love for others to join in. If you’re into Magento or AI, your ideas and contributions are always welcome. Let’s create something awesome together!

## Features
- Generate product descriptions using your own custom prompt for full control and flexibility.
- Customize prompt templates using %s (attribute) and %d (word count)
- Choose OpenAI model (gpt-4, gpt-3.5-turbo, text-davinci-003, etc.)
- Set target word count for short and full descriptions
- Supports both:
    - text-davinci-003 (completion endpoint)
    - gpt-3.5-turbo, gpt-4, gpt-4o, etc. (chat endpoint)
- Clean, valid HTML output ready to use in WYSIWYG editor
- Compatible with Page Builder

## Usage
1. Go to `Stores > Configuration > Mageprince > Mage AI`
2. Add your OpenAI API Secret (ensure your quota allows access to the chosen model)
3. Open any product and click “Generate with MageAI” in the Short/Long Description editor
4. Watch the AI generate polished, HTML-ready content instantly
5. Save Product
 

## How to install
### Install module via composer 

Run the following command in Magento 2 root folder:

```
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

You’re very welcome to join in.

**To contribute:**
1. Fork the repository
2. Create your feature branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -m 'Add new feature'`
4. Push to the branch: `git push origin feature/my-feature`
5. Create a new Pull Request

## Generate Descriptions with Custom Prompt
![mageprince-mageai-final](https://github.com/user-attachments/assets/9fbdad1b-5d27-4dbb-a3ca-274f3036ba34)

## Generate Product Short Description
![short-description](https://github.com/user-attachments/assets/a88899fc-d70a-4138-9295-e79b4efd1308)

## Generate Product Long Description With Page Builder
![full-description-short](https://github.com/user-attachments/assets/54031479-5d68-4af9-9fc1-c24680e4a858)

## Admin Configuration
![configuration](https://github.com/user-attachments/assets/98a9c802-93d6-4b80-b8b6-e5be375a6915)

