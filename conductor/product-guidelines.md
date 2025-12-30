# Product Guidelines: Wicket Guest Checkout

## Voice & Tone
- **Professional and Authoritative:** All user-facing communications, including emails and notifications, must be clear, direct, and serious. This builds the necessary trust for financial transactions and security-related functionality.
- **Action-Oriented:** Language should drive the user toward completing their task (e.g., "Pay Now", "Review Order").

## Interface & Messaging
- **Security-First Language:** Use terms like "Secure Payment," "Verified Link," and "Encrypted" to reinforce user confidence in the payment process.
- **Direct & Active Voice:** Provide clear, unmistakable instructions.
- **Actionable Error Handling:** Error messages (especially for expired links or failed payments) must explain *why* the error occurred and provide a clear path to resolution (e.g., "This link has expired. Please contact the sender for a new link.").

## Visual Design Principles
- **Native Integration:** Any UI elements must seamlessly match the WordPress admin dashboard and the store's WooCommerce theme.
- **High Contrast & Clarity:** Ensure that critical action buttons (like payment buttons) and status indicators are highly visible and accessible.
- **Trust Indicators:** Incorporate subtle visual cues like lock icons or security badges near sensitive actions to reassure users.

## Developer & Code Guidelines
- **PSR-12 Standard:** All PHP code must adhere to the PSR-12 coding standard.
- **Project Consistency:** New code must follow the established architectural patterns and naming conventions already present in the plugin.
- **Clarity over Cleverness:** Prioritize readable, well-documented code that follows WordPress and WooCommerce best practices.
- **Defensive Programming:** Maintain a high standard for error handling, input sanitization, and output escaping, especially given the project's focus on secure payments.
