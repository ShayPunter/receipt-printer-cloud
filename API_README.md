# Action Item Extractor API

A Laravel application that receives webhook data, uses Groq AI to extract actionable items, and provides a sync endpoint for external applications.

## Features

- **Webhook Endpoints**: Receive data from various sources (Gmail, Slack, etc.)
- **AI-Powered Extraction**: Uses Groq AI to intelligently extract actionable items from message content
- **Sync System**: External applications can periodically sync extracted action items
- **API Key Authentication**: Optional security layer for all endpoints
- **Database Tracking**: Tracks sync status for each action item

## Quick Start

### 1. Installation

```bash
# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate
```

### 2. Configuration

Edit your `.env` file:

```env
# Optional: Set API key for endpoint security (leave empty to disable)
API_KEY=your-secure-api-key-here

# Required: Get your Groq API key from https://console.groq.com/keys
GROQ_API_KEY=gsk_your_groq_api_key_here

# Database (SQLite by default)
DB_CONNECTION=sqlite
```

### 3. Start the Server

```bash
php artisan serve
```

Your API will be available at `http://localhost:8000`

## API Endpoints

All endpoints are prefixed with `/api`. If you set an `API_KEY` in your `.env`, include it in requests:
- Header: `X-API-Key: your-api-key`
- Query param: `?api_key=your-api-key`

### Webhook Endpoints

#### 1. Store Message (Queued Processing)

Store a message for later processing.

```bash
POST /api/webhook
Content-Type: application/json

{
  "source": "gmail",
  "body": "Don't forget to submit the quarterly report by Friday and review John's pull request."
}
```

**Response:**
```json
{
  "success": true,
  "message": "Message received and queued for processing",
  "data": {
    "message_id": 1,
    "source": "gmail",
    "processed": false
  }
}
```

#### 2. Process Message

Process a stored message and extract action items.

```bash
POST /api/webhook/process/1
```

**Response:**
```json
{
  "success": true,
  "message": "Message processed successfully",
  "data": {
    "message_id": 1,
    "action_items_count": 2,
    "action_items": [
      "Submit the quarterly report by Friday",
      "Review John's pull request"
    ]
  }
}
```

#### 3. Store and Process Immediately

Receive webhook data and process it immediately in one call.

```bash
POST /api/webhook/process-immediately
Content-Type: application/json

{
  "source": "slack",
  "body": "Team meeting tomorrow at 10 AM. Please prepare the demo and send the agenda by EOD."
}
```

**Response:**
```json
{
  "success": true,
  "message": "Message received and processed successfully",
  "data": {
    "message_id": 2,
    "source": "slack",
    "action_items_count": 2,
    "action_items": [
      "Attend team meeting tomorrow at 10 AM",
      "Prepare the demo and send the agenda by EOD"
    ]
  }
}
```

### Sync Endpoints

#### 1. Get Unsynced Action Items

Retrieve all action items that haven't been synced yet.

```bash
GET /api/sync
GET /api/sync?source=gmail        # Filter by source
GET /api/sync?limit=50            # Limit results (default: 100, max: 500)
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "action": "Submit the quarterly report by Friday",
      "source": "gmail",
      "message_id": 1,
      "message_body": "Don't forget to submit the quarterly report...",
      "created_at": "2025-01-10T10:30:00.000000Z"
    },
    {
      "id": 2,
      "action": "Review John's pull request",
      "source": "gmail",
      "message_id": 1,
      "message_body": "Don't forget to submit the quarterly report...",
      "created_at": "2025-01-10T10:30:00.000000Z"
    }
  ],
  "count": 2
}
```

#### 2. Mark Action Items as Synced

Mark action items as synced after successfully syncing them locally.

```bash
POST /api/sync/mark-synced
Content-Type: application/json

{
  "action_item_ids": [1, 2, 3]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Action items marked as synced",
  "data": {
    "updated_count": 3
  }
}
```

#### 3. Get Sync Statistics

Get overview of sync status.

```bash
GET /api/sync/stats
```

**Response:**
```json
{
  "success": true,
  "data": {
    "total_action_items": 10,
    "unsynced_count": 3,
    "synced_count": 7,
    "by_source": [
      {
        "source": "gmail",
        "total": 6,
        "synced": 4,
        "unsynced": 2
      },
      {
        "source": "slack",
        "total": 4,
        "synced": 3,
        "unsynced": 1
      }
    ]
  }
}
```

## Usage Example

### Complete Workflow

```bash
# 1. Send webhook data
curl -X POST http://localhost:8000/api/webhook/process-immediately \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{
    "source": "gmail",
    "body": "Please review the contract and schedule a follow-up meeting with the client."
  }'

# 2. External app periodically syncs (e.g., every 30 seconds)
curl http://localhost:8000/api/sync \
  -H "X-API-Key: your-api-key"

# 3. Mark items as synced after storing locally
curl -X POST http://localhost:8000/api/sync/mark-synced \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{
    "action_item_ids": [1, 2]
  }'
```

### With curl and jq (Pretty Output)

```bash
# Process immediately and format output
curl -X POST http://localhost:8000/api/webhook/process-immediately \
  -H "Content-Type: application/json" \
  -d '{"source":"gmail","body":"Call Sarah about the budget and update the timeline."}' \
  | jq .

# Get unsynced items
curl http://localhost:8000/api/sync | jq '.data[] | {id, action, source}'
```

## Database Schema

### Messages Table
- `id`: Primary key
- `source`: Source of the message (e.g., "gmail", "slack")
- `body`: Raw message content
- `processed`: Whether action items have been extracted
- `created_at`, `updated_at`: Timestamps

### Action Items Table
- `id`: Primary key
- `message_id`: Foreign key to messages
- `source`: Denormalized source for easier querying
- `action`: The extracted actionable item
- `synced`: Whether this item has been synced to external app
- `synced_at`: When it was synced
- `created_at`, `updated_at`: Timestamps

## How It Works

1. **Webhook receives data**: A message is stored with `source` and `body`
2. **Groq AI processes**: The message body is sent to Groq's AI with a prompt to extract actionable items
3. **Action items stored**: Each extracted action is saved as a separate record linked to the original message
4. **External app syncs**: Your external app polls `/api/sync` to get unsynced items
5. **Mark as synced**: After successfully storing locally, the external app marks items as synced

## Groq AI Integration

The application uses Groq's fast inference API with the Mixtral model to extract actionable items from message content.

**What counts as an actionable item:**
- Tasks to complete
- Items to review
- Things to respond to
- Meetings to attend
- Decisions to make
- Information to provide

The AI returns a JSON array of clear, concise action items. If no actions are found, it returns an empty array.

## Error Handling

All endpoints return JSON responses with a `success` boolean and appropriate HTTP status codes:

- `200`: Success
- `201`: Resource created
- `400`: Bad request (e.g., already processed)
- `401`: Unauthorized (invalid/missing API key)
- `404`: Resource not found
- `422`: Validation failed
- `500`: Server error

**Example error response:**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "source": ["The source field is required."],
    "body": ["The body field is required."]
  }
}
```

## Testing

```bash
# Run all tests
php artisan test

# Run with coverage
php artisan test --coverage
```

## Development

### Running Queue Workers (Optional)

If you want to process messages asynchronously:

```bash
php artisan queue:work
```

Update `WebhookController@store` to dispatch a job instead of processing immediately.

### Logging

Logs are stored in `storage/logs/laravel.log`. Key events logged:
- Webhook messages received
- AI processing results
- Action items synced
- Errors and exceptions

## Security Considerations

1. **API Key**: Always set an `API_KEY` in production
2. **HTTPS**: Use HTTPS in production to encrypt API key transmission
3. **Rate Limiting**: Consider adding rate limiting middleware for production use
4. **Input Validation**: All inputs are validated before processing

## License

MIT License
