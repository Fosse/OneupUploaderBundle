<?php

namespace Oneup\UploaderBundle\Controller;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\UploadException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use Oneup\UploaderBundle\Controller\AbstractChunkedController;
use Oneup\UploaderBundle\Uploader\Response\MooUploadResponse;

class MooUploadController extends AbstractChunkedController
{
    protected $response;
    
    public function upload()
    {
        $request = $this->container->get('request');
        $dispatcher = $this->container->get('event_dispatcher');
        $translator = $this->container->get('translator');
        
        $response = new MooUploadResponse();
        $files = $request->files;
        $headers = $request->headers;
        
        // we have to get access to this object in another method
        $this->response = $response;
        
        // create temporary file in systems temp dir
        $tempFile = tempnam(sys_get_temp_dir(), 'uploader');
        $contents = file_get_contents('php://input');
        
        // put data from php://input to temp file
        file_put_contents($tempFile, $contents);
        
        $uploadFileName = sprintf('%s_%s', $headers->get('x-file-id'), $headers->get('x-file-name'));
        
        // create an uploaded file to upload
        $file = new UploadedFile($tempFile, $uploadFileName, null, null, null, true);
        
        // check if uploaded by chunks
        $chunked = $headers->get('content-length') < $headers->get('x-file-size');
        
        try
        {
            $uploaded = $chunked ? $this->handleChunkedUpload($file) : $this->handleUpload($file);
            
            // fill response object
            $response = $this->response;
            
            $response->setId($headers->get('x-file-id'));
            $response->setSize($headers->get('content-length'));
            $response->setName($headers->get('x-file-name'));
            $response->setUploadedName($uploadFileName);
            
            // dispatch POST_PERSIST AND POST_UPLOAD events
            $this->dispatchEvents($uploaded, $response, $request);
        }
        catch(UploadException $e)
        {
            $response = $this->response;
            
            $response->setFinish(true);
            $response->setError(-1);
            
            // return nothing
            return new JsonResponse($response->assemble());
        }
        
        return new JsonResponse($response->assemble());
    }
    
    protected function parseChunkedRequest(Request $request)
    {
        $chunkManager = $this->container->get('oneup_uploader.chunk_manager');
        $headers = $request->headers;
        $parameters = array_keys($request->query->all());
        
        $uuid  = $headers->get('x-file-id');
        $index = $this->createIndex($parameters[0]);
        $orig  = $headers->get('x-file-name');
        $size  = 0;
        
        try
        {
            // loop through every file that has been uploaded before
            foreach($chunkManager->getChunks($uuid) as $file)
            {
                $size += $file->getSize();
            }
        }
        catch(\InvalidArgumentException $e)
        {
            // do nothing: this exception will be thrown
            // if the directory does not yet exist. this
            // means we don't have a chunk and the actual
            // size is 0
        }
        
        $last = $headers->get('x-file-size') == ($size + $headers->get('content-length'));
        
        // store also to response object
        $this->response->setFinish($last);
        
        return array($last, $uuid, $index, $orig);
    }
    
    protected function createIndex($id)
    {
        $ints = 0;
        
        // loop through every char and convert it to an integer
        // we need this for sorting
        foreach(str_split($id) as $char)
        {
            $ints += ord($char);
        }
        
        return $ints;
    }
}