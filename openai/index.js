import OpenAI from 'openai';

const client = new OpenAI({
  apiKey: 'sk-proj-*',
});

/* const response = await client.responses.create({
  model: 'gpt-4o',
  input: 'What is the capital of France?',
});
console.log(response.output_text); */

/* const response = await client.images.generate({
  model: 'dall-e-2',
  n: 1,
  prompt: 'Create a realistic, high-quality postcard design centered on plumbing. The primary feature is a South Asian male plumber in a professional stance against a solid light-blue backdrop. All text elements are in solid white color for high contrast and readability, using the Helvetica font. The text includes \'Bathroom Repair Set\' in large size at the top, followed vertically by \'Toilet Flapper\', \'Showerhead\', and \'Seal Rings\' in medium size. At the bottom is a small-sized phrase reading \'First and professional help\'. All text blocks are perfectly horizontal with no tilting or rotation.',
  response_format: 'url',
  size: '1024x1024',
});
console.log(response.data.map(item => item.url)); */

const response = await client.images.generate({
  model: 'dall-e-3',
  n: 1,
  prompt: 'Create a realistic, high-quality postcard design centered on plumbing. The primary feature is a South Asian male plumber in a professional stance against a solid light-blue backdrop. All text elements are in solid white color for high contrast and readability, using the Helvetica font. The text includes \'Bathroom Repair Set\' in large size at the top, followed vertically by \'Toilet Flapper\', \'Showerhead\', and \'Seal Rings\' in medium size. At the bottom is a small-sized phrase reading \'First and professional help\'. All text blocks are perfectly horizontal with no tilting or rotation.',
  quality: 'standard',
  response_format: 'url',
  size: '1024x1024',
  style: 'vivid', // natural
});
console.log(response.data.map(item => item.url));
