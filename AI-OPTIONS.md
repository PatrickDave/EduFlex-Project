# Running the AI without paying

You asked for a zero-cost option. There is one, and the decision affects
architecture, so read this before the next phase starts.

Important caveat: free tiers, model names and limits change constantly, and my
information may be out of date. Treat the options below as directions to
check, not as current fact. Verify the terms yourself before committing.

---

## 1. Build it provider-agnostic, whatever you choose

This is the part that matters most, and it costs nothing to do.

Do not scatter calls to one provider through your code. Put every AI call
behind one interface:

    interface AiProvider {
        public function complete(string $systemPrompt, string $userPrompt, ?array $schema): array;
    }

Then write one small driver per provider. Switching later becomes a one-line
config change instead of a rewrite. If a free tier closes, or your instructors
insist on a specific model, or one provider produces bad questions, you swap
the driver and everything else stands.

For a four-month build with an unresolved cost question, this is not
over-engineering. It is insurance.

---

## 2. The genuinely free options

### Run the model on your own machine

Tools like Ollama let you download an open model and run it locally. Your PHP
calls `http://localhost:11434` instead of a remote API.

Good:

- No cost at all, ever. No card, no account, no quota.
- No API key to leak or protect.
- Works with no internet, which makes your defense demo immune to campus Wi-Fi.
- No rate limits, so you can generate as much test data as you like.

Bad:

- Needs a capable machine. A small model wants roughly 6 to 8 GB of free RAM.
  Your Chapter III developer hardware spec says 16 GB minimum, so this fits,
  but check the actual machine you will demo on.
- Slower than a hosted API, especially without a dedicated GPU.
- Output quality is below the large hosted models. Question generation will
  need a tighter prompt and stricter validation.
- Every evaluator hits your one machine, so it must be the server.

This is the option I would pick for your situation. Cost is the constraint you
named, and this removes it entirely.

### Hosted free tiers

Several providers offer a free quota with rate limits, and some let you start
without a payment method. They are faster and produce better output than a
local small model.

Watch for:

- Requests per minute and per day caps. Thirty evaluators clicking at once can
  exhaust a minute's quota, so queue requests and handle a 429 response by
  retrying rather than showing an error.
- Whether free-tier inputs may be used to train the provider's models. Your
  Ethics section promises to handle learner data responsibly, so read the terms
  and say in Chapter III what you found.
- Whether a card is required to activate the key at all.

---

## 3. What this means for your manuscript

Your manuscript names a specific family only once. In Ethical Considerations:

> Users will be informed that the system uses a GPT-based Large Language Model
> to analyze learning resources...

If you end up running a non-GPT model, that sentence becomes inaccurate. Change
it to name whatever you actually use, or generalise it:

> Users will be informed that the system uses a large language model to analyze
> learning resources...

Everywhere else you wrote "large language model" or "AI model", which is
already accurate whatever you choose. This is a one-sentence fix, not a
rewrite. Make it before the final defense, not after a panelist finds it.

---

## 4. Cost control that applies to every option

Even on a paid tier these keep the bill small, and on a free tier they keep you
inside the quota.

- **Cache aggressively.** Topic extraction runs once per upload, never again.
  Generated questions are stored in `activity_item` and reused. A learner
  repeating a topic gets the stored set, not a fresh generation.
- **Never send the whole document.** Send the two or three relevant chunks.
  Your `resource_chunk` table already exists for this.
- **Keep mastery out of the model.** The calculation is arithmetic and runs in
  PHP for free. Routing it through an LLM would be the single most wasteful
  thing you could do.
- **Cap it per learner.** A simple daily generation limit stops one evaluator
  from consuming everything.
- **Log every call** in `ai_interaction`, which you already have. You cannot
  manage a cost you are not measuring, and the log is also evidence for your
  Chapter IV analysis.

---

## 5. How this is wired, now that it is built

Every model call in EduFlex goes through one interface, `AiProvider` in
`includes/ai.php`. Nothing else in the codebase knows which provider is in use.

Three drivers ship:

| Driver | Use it for |
|---|---|
| `openai-compatible` | OpenAI and the many hosts that copy its request shape, including Groq and OpenRouter. Set `AI_BASE_URL` to that provider's endpoint. |
| `gemini` | Google's Generative Language API, which uses a different shape. |
| `mock` | Building and demoing with no key, no network and no cost. |

Switching provider is four lines in `config/ai.php`. If your free tier closes or
the output quality disappoints, you change those lines and nothing else.

The retry behaviour is already in place: a 429 or a 5xx is retried three times
with increasing waits, because free tiers throttle and a throttle is not an
error worth showing a learner. A 401 is never retried, because a wrong key stays
wrong.

### One caution about output quality

The pipeline is tested and the plumbing is proven, but I could not make a real
API call from my environment to check what your provider actually returns. The
first thing to do after setting your key is run detection on a document you know
well and read the topics it produces.

If they are wrong, the fix is usually the prompt rather than the provider. It
lives in `topics_system_prompt()` in `includes/topics.php`, in plain English,
and you can edit it directly.
