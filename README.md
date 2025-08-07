# YouTube Transcript Fetcher Module for Drupal

This module integrates with the YouTube API to fetch video transcripts and associate them with Drupal taxonomy terms.

## Features

- Fetches transcripts for YouTube videos linked in taxonomy terms.
- Stores transcripts in a designated field within the term.
- Provides an admin interface for configuration and manual fetching.

## Requirements

- Drupal 9 or higher.
- Composer.
- Google API Client Library for PHP.

## Installation

1. **Clone the Module**

   Place the module in your Drupal installation's `modules/custom` directory:

   ```bash
   cd /path/to/drupal/modules/custom
   git clone https://github.com/your-repo/youtube_transcript.git
Install Dependencies

Use Composer to install the required Google API Client Library:


composer require google/apiclient
Enable the Module

Enable the module via Drush or the Drupal admin interface:


drush en youtube_transcript
Configuration
Google API Setup
Create a Project in Google Cloud Console

Navigate to the Google Cloud Console.
Create a new project or select an existing one.
Enable YouTube Data API v3

In the API Library, search for "YouTube Data API v3".
Click "Enable" to add it to your project.
Set Up OAuth Consent Screen

Navigate to "OAuth consent screen" in the API & Services section.
Configure the consent screen with the necessary information.
Create OAuth Client Credentials

Go to "Credentials" and click "Create Credentials" > "OAuth client ID".
Select "Web application" and configure the authorized redirect URIs:
https://yourdomain.com/user/login/google/callback
Download Client Secret JSON

After creating the credentials, download the client_secret.json file.
Place this file in your Drupal site's private files directory (e.g., private://client_secret.json).
Drupal Configuration
Configure Private File System

Ensure that the private file system is correctly configured in your settings.php:



$settings['file_private_path'] = '/path/to/private/files';
Set Up YouTube Transcript Settings

Navigate to Configuration > YouTube Transcript Settings.
Enter your Google OAuth Client ID, Client Secret, and Redirect URI.
Save the configuration.
Authentication
Initiate OAuth Flow

After saving the configuration, you'll be prompted to authenticate with your Google account.
Click on the provided link to start the OAuth authentication process.
Grant Permissions

Log in with the Google account that has access to the YouTube videos.
Grant the necessary permissions when prompted.
Token Storage

Upon successful authentication, the access and refresh tokens are stored in private://youtube_oauth_token.json.
Ensure that the private directory is writable and secure.
Usage
Automatic Fetching
The module fetches transcripts for taxonomy terms containing YouTube URLs upon saving the term.
Manual Fetching
Fetch All Transcripts

Navigate to Configuration > YouTube Transcript Settings.
Click "Fetch All Transcripts" to retrieve transcripts for all relevant terms.
Troubleshooting
Invalid YouTube URL
Ensure the URL is in the format https://www.youtube.com/watch?v=VIDEO_ID or https://youtu.be/VIDEO_ID.
Authentication Errors
If you encounter errors related to missing refresh tokens:
Re-authenticate via the module's configuration page.
Ensure the private:// file system is correctly configured and writable.
Token Storage Issues
Verify that youtube_oauth_token.json exists in the private:// directory.
Check file permissions to ensure the web server can read and write to this file.
Testing
To verify the module's functionality, you can use the following Python script to fetch transcripts independently:


import re
from googleapiclient.discovery import build
from google.oauth2.credentials import Credentials

# Load OAuth2 credentials
def load_credentials():
    return Credentials.from_authorized_user_file("youtube_token.json")

# Function to clean and properly format transcript
def clean_srt(srt_text):
    """Removes timestamps, numbers, and intelligently formats text into paragraphs."""
    lines = srt_text.split("\n")
    cleaned_text = []
    sentence_buffer = ""

    for line in lines:
        # Remove numeric sequence numbers
        if re.match(r'^\d+$', line):
            continue
        # Remove timestamps (e.g., 00:00:03,840 --> 00:00:08,440)
        if re.match(r'^\d{2}:\d{2}:\d{2},\d{3} -->', line):
            continue

        line = line.strip()

        if line:
            # If the line does NOT end in punctuation, assume it's a broken sentence
            if not re.search(r'[.!?]$', line):
                sentence_buffer += line + " "
            else:
                sentence_buffer += line
                cleaned_text.append(sentence_buffer.strip())
                sentence_buffer = ""

    # Append any remaining sentence
    if sentence_buffer:
        cleaned_text.append(sentence_buffer.strip())

    # Join sentences into paragraphs
    return "\n\n".join(cleaned_text)

# Fetch captions for a given video ID (Preferring Manual Captions)
def fetch_captions(video_id):
    creds = load_credentials()
    youtube = build("youtube", "v3", credentials=creds)

    # Get available caption tracks
    request = youtube.captions().list(
        part="snippet",
        videoId=video_id
    )
    response = request.execute()

    manual_caption_id = None
    auto_caption_id = None

    for item in response.get("items", []):
        caption_id = item["id"]
        language = item["snippet"]["language"]
        track_kind = item["snippet"].get("trackKind", "")

        if track_kind == "standard":  # Manually uploaded captions
            manual_caption_id = caption_id
        elif track_kind == "asr":  # Auto-generated captions
            auto_caption_id = caption_id

    # Prefer manually uploaded captions
    caption_id_to_use = manual_caption_id if manual_caption_id else auto_caption_id

    if not caption_id_to_use:
        print("No captions available for this video.")
        return ""

    print(f"Fetching {'manual' if manual_caption_id else 'auto-generated'} captions in {language}...")

    # Download the selected caption track
    caption_request = youtube.captions().download(
        id=caption_id_to_use,
        tfmt="srt"
    )
    caption_response = caption_request.execute()

    # Clean and format transcript
    return clean_srt(caption_response.decode("utf-8"))

# Example usage
if __name__ == "__main__":
    video_id = "xLle5hnnjUU"  # Replace with actual YouTube video ID
    transcript = fetch_captions(video_id)


::contentReference[oaicite:0]{index=0}