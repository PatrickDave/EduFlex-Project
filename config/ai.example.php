<?php
/**
 * EduFlex — language model configuration TEMPLATE.
 *
 * ============================================================================
 * THIS FILE IS THE TEMPLATE. IT IS COMMITTED. IT MUST NEVER HOLD A REAL KEY.
 *
 * Copy it to config/ai.php and put your key in the copy:
 *
 *     Windows:  copy config\ai.example.php config\ai.php
 *     Mac/Linux: cp config/ai.example.php config/ai.php
 *
 * config/ai.php is listed in .gitignore, so your key stays off GitHub. This
 * template exists so that anyone cloning the repository gets a working file to
 * copy instead of a fatal "undefined constant" error.
 *
 * PUT YOUR API KEY IN config/ai.php, AND NOWHERE ELSE.
 *
 * This file is server-side only. It is never sent to the browser. Do not paste
 * the key into JavaScript, into a page, or into a public repository. If the key
 * ever reaches the browser, anyone can read it and spend your quota.
 *
 * ============================================================================
 */

declare(strict_types=1);

/*
 | Which driver to use.
 |
 |   'openai-compatible'  Works with any provider exposing the OpenAI chat
 |                        completions shape. That covers OpenAI itself and most
 |                        hosted free tiers, including Groq and OpenRouter.
 |                        Set AI_BASE_URL to that provider's endpoint.
 |
 |   'gemini'             Google's Generative Language API, which uses a
 |                        different request shape.
 |
 |   'mock'               No network, no key, no cost. Returns deterministic
 |                        fake output. Use it to develop and to run the tests.
 */
const AI_DRIVER = 'mock';

/* The API key. Leave empty while AI_DRIVER is 'mock'. */
const AI_API_KEY = '';

/*
 | Base URL, for the openai-compatible driver only. No trailing slash.
 | The driver appends /chat/completions.
 |
 | Examples (verify the current host in your provider's own documentation):
 |   https://api.openai.com/v1
 |   https://api.groq.com/openai/v1
 |   https://openrouter.ai/api/v1
 */
const AI_BASE_URL = 'https://api.openai.com/v1';

/* Model name, exactly as your provider spells it. */
const AI_MODEL = 'gpt-4o-mini';

/* Seconds to wait for one response before giving up. */
const AI_TIMEOUT = 60;

/*
 | Free tiers limit requests per minute. On a 429 or a server error the driver
 | waits and tries again, doubling the wait each time.
 */
const AI_MAX_RETRIES = 3;
const AI_RETRY_BASE_MS = 1200;

/*
 | QUOTA GUARD — read this before changing it.
 |
 | Your 155-page manuscript produced 205 chunks. Sending one request per chunk
 | would be 205 calls for a single upload, which exhausts a free tier's daily
 | allowance on one document.
 |
 | Instead EduFlex samples: it takes at most this many chunks, spread evenly
 | across the document, so the topics reflect the whole thing rather than only
 | the beginning. A 205-chunk document costs 12 calls, not 205.
 |
 | Raise it if your quota allows and you want finer coverage.
 */
const AI_MAX_CHUNKS_PER_RESOURCE = 12;

/* Upper bound on topics kept per document, after merging duplicates. */
const AI_MAX_TOPICS_PER_RESOURCE = 25;
